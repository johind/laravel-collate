<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::put('source.pdf', file_get_contents(fixturePath('single-page.pdf')));
    Storage::put('addition.pdf', file_get_contents(fixturePath('single-page.pdf')));
    Storage::put('overlay.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

describe('input file', function () {
    it('passes the source directly as the main input when there are no additions', function () {
        $pending = makeCollate()->open('source.pdf');
        $command = buildCommand($pending);
        $sourcePath = Storage::path('source.pdf');

        expect($command)->toContain($sourcePath)
            ->and($command)->not->toContain('--empty');
    });

    it('uses the source as the primary input even when there are additions', function () {
        $pending = makeCollate()->open('source.pdf')->addPages('addition.pdf');
        $command = buildCommand($pending);
        $sourcePath = Storage::path('source.pdf');

        expect($command)->toContain($sourcePath)
            ->and($command)->not->toContain('--empty');
    });

    it('uses --empty when there is no source at all', function () {
        $pending = makeCollate()->merge('source.pdf');
        $command = buildCommand($pending);

        expect($command)->toContain('--empty');
    });
});

describe('--pages block', function () {
    it('uses "." to refer to the primary input inside the --pages block', function () {
        $pending = makeCollate()->open('source.pdf');
        $command = buildCommand($pending);

        $pagesIndex = array_search('--pages', $command);

        expect($command[$pagesIndex + 1])->toBe('.')
            ->and($command[$pagesIndex + 2])->toBe('1-z');
    });

    it('passes the page selection into the --pages block', function () {
        $pending = makeCollate()->open('source.pdf')->onlyPages('2-5');
        $command = buildCommand($pending);

        $pagesIndex = array_search('--pages', $command);

        expect($command[$pagesIndex + 2])->toBe('2-5');
    });

    it('the page override supersedes the stored page selection', function () {
        $pending = makeCollate()->open('source.pdf')->onlyPages('2-5');
        $command = buildCommand($pending, '/tmp/out.pdf', '1-3');

        $pagesIndex = array_search('--pages', $command);

        expect($command[$pagesIndex + 2])->toBe('1-3');
    });

    it('appends addition files after the source in the --pages block', function () {
        $pending = makeCollate()->open('source.pdf')->addPages('addition.pdf');
        $command = buildCommand($pending);
        $additionPath = Storage::path('addition.pdf');

        expect($command)->toContain($additionPath);
    });

    it('terminates the --pages block with --', function () {
        $pending = makeCollate()->open('source.pdf');
        $command = buildCommand($pending);

        expect($command)->toContain('--');
    });
});

describe('decrypt', function () {
    it('prepends --password before the input file', function () {
        $pending = makeCollate()->open('source.pdf')->decrypt('secret');
        $command = buildCommand($pending);

        $passwordIndex = array_search('--password=secret', $command);
        $decryptIndex = array_search('--decrypt', $command);

        expect($passwordIndex)->toBeGreaterThan(0)
            ->and($decryptIndex)->toBe($passwordIndex + 1);
    });
});

describe('rotation', function () {
    it('appends the correct rotation flag', function () {
        $pending = makeCollate()->open('source.pdf')->rotate(90, pages: '1-3');
        $command = buildCommand($pending);

        expect($command)->toContain('--rotate=+90:1-3');
    });

    it('appends multiple rotation flags in order', function () {
        $pending = makeCollate()->open('source.pdf')
            ->rotate(90, pages: '1')
            ->rotate(180, pages: '2');
        $command = buildCommand($pending);

        expect($command)->toContain('--rotate=+90:1')
            ->and($command)->toContain('--rotate=+180:2');
    });
});

describe('encryption', function () {
    it('appends --encrypt with passwords and bit length in the correct order', function () {
        $pending = makeCollate()->open('source.pdf')->encrypt('user', 'owner', 256);
        $command = buildCommand($pending);

        $encryptIndex = array_search('--encrypt', $command);

        expect($command[$encryptIndex + 1])->toBe('user')
            ->and($command[$encryptIndex + 2])->toBe('owner')
            ->and($command[$encryptIndex + 3])->toBe('256');
    });

    it('appends the correct per-permission flags from the RESTRICTIONS map', function () {
        $pending = makeCollate()->open('source.pdf')
            ->encrypt('password')
            ->restrict('print', 'extract');
        $command = buildCommand($pending);

        expect($command)->toContain('--print=none')
            ->and($command)->toContain('--extract=n');
    });

    it('appends --allow-weak-crypto for 40-bit encryption', function () {
        $pending = makeCollate()->open('source.pdf')->encrypt('user', 'owner', 40);
        $command = buildCommand($pending);

        expect($command)->toContain('--allow-weak-crypto');
    });

    it('appends --allow-weak-crypto for 128-bit encryption', function () {
        $pending = makeCollate()->open('source.pdf')->encrypt('user', 'owner', 128);
        $command = buildCommand($pending);

        expect($command)->toContain('--allow-weak-crypto');
    });

    it('does not append --allow-weak-crypto for 256-bit encryption', function () {
        $pending = makeCollate()->open('source.pdf')->encrypt('user', 'owner', 256);
        $command = buildCommand($pending);

        expect($command)->not->toContain('--allow-weak-crypto');
    });

    it('restricting print does not silently add --modify=none', function () {
        $pending = makeCollate()->open('source.pdf')
            ->encrypt('password')
            ->restrict('print');
        $command = buildCommand($pending);

        expect($command)->not->toContain('--modify=none');
    });

    it('restricting modify uses --modify=none not --modify=n', function () {
        $pending = makeCollate()->open('source.pdf')
            ->encrypt('password')
            ->restrict('modify');
        $command = buildCommand($pending);

        expect($command)->toContain('--modify=none');
    });

    it('terminates the --encrypt block with --', function () {
        $pending = makeCollate()->open('source.pdf')->encrypt('password');
        $command = buildCommand($pending);

        // The -- after the encrypt block must exist
        $encryptIndex = array_search('--encrypt', $command);
        $sliceAfterEncrypt = array_slice($command, $encryptIndex);

        expect(in_array('--', $sliceAfterEncrypt))->toBeTrue();
    });
});

describe('overlay and underlay', function () {
    it('appends the correct --overlay block', function () {
        $pending = makeCollate()->open('source.pdf')->overlay('overlay.pdf');
        $command = buildCommand($pending);
        $overlayPath = Storage::path('overlay.pdf');

        $overlayIndex = array_search('--overlay', $command);

        expect($command[$overlayIndex + 1])->toBe($overlayPath);
    });

    it('appends the correct --underlay block', function () {
        $pending = makeCollate()->open('source.pdf')->underlay('overlay.pdf');
        $command = buildCommand($pending);
        $underlayPath = Storage::path('overlay.pdf');

        $underlayIndex = array_search('--underlay', $command);

        expect($command[$underlayIndex + 1])->toBe($underlayPath);
    });
});

describe('flatten and linearize', function () {
    it('appends --flatten-annotations=all when flatten() is called', function () {
        $pending = makeCollate()->open('source.pdf')->flatten();
        $command = buildCommand($pending);

        expect($command)->toContain('--flatten-annotations=all');
    });

    it('does not append --flatten-annotations=all by default', function () {
        $pending = makeCollate()->open('source.pdf');
        $command = buildCommand($pending);

        expect($command)->not->toContain('--flatten-annotations=all');
    });

    it('appends --linearize when linearize() is called', function () {
        $pending = makeCollate()->open('source.pdf')->linearize();
        $command = buildCommand($pending);

        expect($command)->toContain('--linearize');
    });

    it('does not append --linearize by default', function () {
        $pending = makeCollate()->open('source.pdf');
        $command = buildCommand($pending);

        expect($command)->not->toContain('--linearize');
    });
});

it('the output path is always the last element', function () {
    $pending = makeCollate()->open('source.pdf');
    $command = buildCommand($pending, '/tmp/specific-output.pdf');

    expect(last($command))->toBe('/tmp/specific-output.pdf');
});
