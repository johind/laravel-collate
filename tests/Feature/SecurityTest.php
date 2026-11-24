<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Johind\Collate\Collate;
use Johind\Collate\Exceptions\ProcessFailedException;
use League\Flysystem\PathTraversalDetected;
use Symfony\Component\VarDumper\VarDumper;

beforeEach(function (): void {
    if (! qpdfAvailable()) {
        skipTest('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::put('multi.pdf', fixtureContents('multi-page.pdf'));
});

/**
 * Show qpdf's view of a PDF's encryption when opened with the given password.
 */
function showEncryption(string $path, string $password = ''): string
{
    return Process::run(['qpdf', '--show-encryption', '--password='.$password, $path])->output();
}

/**
 * Create a qpdf wrapper that logs its arguments (and optionally sleeps)
 * before delegating to the real binary.
 *
 * @return array{binary: string, log: string}
 */
function argumentLoggingQpdf(int $sleepSeconds = 0): array
{
    $dir = sys_get_temp_dir().'/collate-wrapper-'.uniqid();
    mkdir($dir);

    $qpdf = mb_trim(Process::run(['sh', '-c', 'command -v qpdf'])->output());
    $binary = $dir.'/qpdf';
    $log = $dir.'/arguments.log';

    file_put_contents($binary, implode("\n", [
        '#!/bin/sh',
        sprintf('printf "%%s\n" "$*" >> %s', escapeshellarg($log)),
        $sleepSeconds > 0 ? 'sleep '.$sleepSeconds : '',
        sprintf('exec %s "$@"', escapeshellarg($qpdf)),
        '',
    ]));
    chmod($binary, 0755);

    return ['binary' => $binary, 'log' => $log];
}

describe('encryption', function (): void {
    it('keeps the user password from granting owner rights when no owner password is given', function (string $userPassword): void {
        makeCollate()->open('multi.pdf')->encrypt($userPassword)->restrict('print', 'modify')->save('enc.pdf');

        $encryption = showEncryption(Storage::path('enc.pdf'), $userPassword);

        expect($encryption)->toContain('Supplied password is user password')
            ->and($encryption)->not->toContain('Supplied password is owner password')
            ->and($encryption)->toContain('print high resolution: not allowed')
            ->and($encryption)->toContain('modify anything: not allowed');
    })->with([
        'with a user password' => ['user'],
        'with an empty user password' => [''],
    ]);

    it('encrypts every split page and preserves restrictions', function (int $bits): void {
        $paths = makeCollate()->open('multi.pdf')
            ->encrypt('user', 'owner', $bits)
            ->restrict('print')
            ->split('split/page-{page}.pdf');

        expect($paths)->toHaveCount(5);

        $paths->each(function (string $path): void {
            $file = Storage::path($path);

            expect(Process::run(['qpdf', '--requires-password', $file])->exitCode())->toBe(0)
                ->and(showEncryption($file, 'user'))->toContain('print high resolution: not allowed')
                ->and(showEncryption($file, 'owner'))->toContain('Supplied password is owner password');
        });
    })->with([
        '256-bit' => [256],
        '128-bit, which needs --allow-weak-crypto' => [128],
    ]);

    it('round-trips passwords with spaces and special characters', function (string $user, string $owner): void {
        makeCollate()->open('multi.pdf')->encrypt($user, $owner)->withMetadata(title: 'T')->save('enc.pdf');

        expect(showEncryption(Storage::path('enc.pdf'), $user))->toContain('Supplied password is user password')
            ->and(showEncryption(Storage::path('enc.pdf'), $owner))->toContain('Supplied password is owner password')
            ->and(makeCollate()->open('enc.pdf')->decrypt($user)->pageCount())->toBe(5);
    })->with([
        'spaces, quotes and option-like values' => [' user pass "with" $pecial @chars ', '--owner @file'],
        'multibyte UTF-8' => ['pässwörd ✓', 'Eigentümer 🔒'],
    ]);
});

describe('password exposure', function (): void {
    it('never passes passwords as process arguments', function (): void {
        ['binary' => $binary, 'log' => $log] = argumentLoggingQpdf();
        $collate = new Collate($binary, 'local', sys_get_temp_dir().'/collate-tests');

        $collate->open('multi.pdf')
            ->encrypt('user-secret', 'owner-secret')
            ->withMetadata(title: 'Secret')
            ->split('split/page-{page}.pdf');

        $collate->open('split/page-1.pdf')->decrypt('user-secret')->save('decrypted.pdf');

        $arguments = fileContents($log);

        expect($arguments)->toContain('--password-file=')
            ->and($arguments)->not->toContain('user-secret')
            ->and($arguments)->not->toContain('owner-secret');
    });

    it('removes temporary password files after running qpdf', function (): void {
        $tempDirectory = sys_get_temp_dir().'/collate-secrets-'.uniqid();

        new Collate('qpdf', 'local', $tempDirectory)->open('multi.pdf')->encrypt('user')->content();

        expect(glob($tempDirectory.'/secret-*.json'))->toBe([]);
    });

    it('does not leak passwords when qpdf times out', function (): void {
        ['binary' => $binary] = argumentLoggingQpdf(sleepSeconds: 3);
        $collate = new Collate($binary, 'local', sys_get_temp_dir().'/collate-tests', timeout: 1);

        $exception = null;

        try {
            $collate->open('multi.pdf')->decrypt('decrypt-secret')->encrypt('user-secret', 'owner-secret')->content();
        } catch (ProcessFailedException $processFailedException) {
            $exception = $processFailedException;
        }

        expect($exception)->toBeInstanceOf(ProcessFailedException::class);
        assert($exception instanceof ProcessFailedException);

        expect($exception->getMessage())->toBe('Collate: qpdf timed out after 1 seconds.')
            ->and($exception->getMessage().$exception->errorOutput)->not->toContain('secret');
    });

    it('redacts passwords in dump() output', function (): void {
        $dumped = [];
        $previous = VarDumper::setHandler(function (mixed $value) use (&$dumped): void {
            $dumped[] = $value;
        });

        try {
            makeCollate()->open('multi.pdf')->decrypt('decrypt-secret')->encrypt('user-secret', 'owner-secret')->dump();
        } finally {
            VarDumper::setHandler($previous);
        }

        expect($dumped)->toHaveCount(1)
            ->and($dumped[0])->toBeArray();

        /** @var list<string> $arguments */
        $arguments = $dumped[0];
        $command = implode(' ', $arguments);

        expect($command)->toContain('--password=********')
            ->and($command)->toContain('--encrypt --user-password=******** --owner-password=******** --bits=256')
            ->and($command)->not->toContain('secret');
    });
});

describe('file paths', function (): void {
    it('rejects paths that escape the disk root', function (string $path): void {
        $outside = dirname(Storage::path('')).'/outside.pdf';
        copy(fixturePath('single-page.pdf'), $outside);

        expect(fn (): mixed => makeCollate()->open($path))
            ->toThrow(PathTraversalDetected::class);
    })->with([
        'parent directory' => ['../outside.pdf'],
        'through a subdirectory' => ['nested/../../outside.pdf'],
    ]);

    it('still resolves relative paths inside the disk root', function (): void {
        Storage::put('nested/doc.pdf', fixtureContents('single-page.pdf'));

        expect(makeCollate()->open('nested/../nested/./doc.pdf')->pageCount())->toBe(1);
    });
});

describe('page ranges', function (): void {
    it('counts huge page ranges without iterating over every number', function (string $range, int $expected): void {
        $start = microtime(true);

        $count = makeCollate()->open('multi.pdf')->onlyPages($range)->pageCount();

        expect($count)->toBe($expected)
            ->and(microtime(true) - $start)->toBeLessThan(1.0);
    })->with([
        'ascending' => ['1-999999999999999', 5],
        'descending' => ['999999999999999-2', 4],
        'beyond the last page' => ['100000000-999999999999999', 0],
        'relative to the end' => ['r999999999999999-z', 5],
    ]);
});

describe('temporary files', function (): void {
    it('creates the temp directory readable only by the owner', function (): void {
        $tempDirectory = sys_get_temp_dir().'/collate-perms-'.uniqid();

        new Collate('qpdf', 'local', $tempDirectory)->open('multi.pdf')->content();

        expect(fileperms($tempDirectory) & 0777)->toBe(0700);
    });

    // An install upgraded from an earlier version may still have a temp
    // directory that other users can read, so the files must be private.
    it('keeps processed files private in an existing world-readable temp directory', function (string $metadata): void {
        $tempDirectory = sys_get_temp_dir().'/collate-perms-'.uniqid();
        mkdir($tempDirectory);
        chmod($tempDirectory, 0755);

        $pending = new Collate('qpdf', 'local', $tempDirectory)->open('multi.pdf');
        $pending = match ($metadata) {
            'add' => $pending->withMetadata(title: 'Secret'),
            'strip' => $pending->withoutMetadata(),
            default => $pending,
        };
        $pending->content();

        expect(fileperms(getStringProperty($pending, 'processedPath')) & 0777)->toBe(0600);
    })->with([
        'plain' => ['none'],
        'with metadata' => ['add'],
        'without metadata' => ['strip'],
    ]);

    it('writes split pages into a private directory and removes it afterwards', function (): void {
        $tempDirectory = sys_get_temp_dir().'/collate-perms-'.uniqid();
        mkdir($tempDirectory);
        chmod($tempDirectory, 0755);

        // Record the permissions of the directory qpdf writes the pages to.
        $wrapper = sys_get_temp_dir().'/collate-split-wrapper-'.uniqid();
        $log = $wrapper.'.log';
        $qpdf = mb_trim(Process::run(['sh', '-c', 'command -v qpdf'])->output());
        file_put_contents($wrapper, implode("\n", [
            '#!/bin/sh',
            'for last; do :; done',
            sprintf('case "$*" in *--split-pages*) ls -ld "$(dirname "$last")" >> %s;; esac', escapeshellarg($log)),
            sprintf('exec %s "$@"', escapeshellarg($qpdf)),
            '',
        ]));
        chmod($wrapper, 0755);

        $paths = new Collate($wrapper, 'local', $tempDirectory)->open('multi.pdf')->split('split/page-{page}.pdf');

        expect($paths)->toHaveCount(5)
            ->and(fileContents($log))->toStartWith('drwx------')
            ->and(glob($tempDirectory.'/*', GLOB_ONLYDIR))->toBe([]);
    });
});
