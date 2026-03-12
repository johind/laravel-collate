<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Collate;
use Johind\Collate\PendingCollate;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

it('stores the binary path', function (): void {
    $collate = new Collate('/usr/bin/qpdf');

    expect($collate->binaryPath())->toBe('/usr/bin/qpdf');
});

it('stores the disk name', function (): void {
    $collate = new Collate('qpdf', 's3');

    expect($collate->diskName())->toBe('s3');
});

it('stores the temp directory', function (): void {
    $collate = new Collate('qpdf', null, '/tmp/collate');

    expect($collate->tempDirectory())->toBe('/tmp/collate');
});

describe('fromDisk()', function (): void {
    it('returns a new instance', function (): void {
        $original = makeCollate();
        $cloned = $original->fromDisk('s3');

        expect($cloned)->not->toBe($original);
    });

    it('does not mutate the original instance', function (): void {
        $original = makeCollate('local');
        $original->fromDisk('s3');

        expect($original->diskName())->toBe('local');
    });

    it('sets the disk name on the cloned instance', function (): void {
        $cloned = makeCollate('local')->fromDisk('s3');

        expect($cloned->diskName())->toBe('s3');
    });
});

describe('open()', function (): void {
    it('returns a PendingCollate', function (): void {
        expect(makeCollate()->open('doc.pdf'))->toBeInstanceOf(PendingCollate::class);
    });

    it('sets the source file on the builder', function (): void {
        $pending = makeCollate()->open('doc.pdf');

        expect(getProperty($pending, 'source'))->not->toBeNull();
    });
});

describe('inspect()', function (): void {
    it('returns a PendingCollate', function (): void {
        expect(makeCollate()->inspect('doc.pdf'))->toBeInstanceOf(PendingCollate::class);
    });

    it('sets the source file on the builder', function (): void {
        $pending = makeCollate()->inspect('doc.pdf');

        expect(getProperty($pending, 'source'))->not->toBeNull();
    });
});

describe('merge()', function (): void {
    beforeEach(function (): void {
        Storage::put('b.pdf', file_get_contents(fixturePath('single-page.pdf')));
    });

    it('returns a PendingCollate', function (): void {
        expect(makeCollate()->merge('doc.pdf', 'b.pdf'))->toBeInstanceOf(PendingCollate::class);
    });

    it('populates additions for each file passed', function (): void {
        $pending = makeCollate()->merge('doc.pdf', 'b.pdf');

        expect(getProperty($pending, 'additions'))->toHaveCount(2);
    });

    it('can accept a single array of files', function (): void {
        $pending = makeCollate()->merge(['doc.pdf', 'b.pdf']);

        expect(getProperty($pending, 'additions'))->toHaveCount(2);
    });

    it('handles mixed array and string arguments correctly', function (): void {
        $pending = makeCollate()->merge(['doc.pdf'], 'b.pdf');

        expect(getProperty($pending, 'additions'))->toHaveCount(2)
            ->and(getProperty($pending, 'additions')[0]['file'])->toBe(Storage::path('doc.pdf'))
            ->and(getProperty($pending, 'additions')[1]['file'])->toBe(Storage::path('b.pdf'));
    });

    it('closure receives and can mutate the pending instance', function (): void {
        $pending = makeCollate()->merge(function (PendingCollate $pdf): void {
            $pdf->addPages('doc.pdf');
        });

        expect(getProperty($pending, 'additions'))->toHaveCount(1);
    });

    it('does not set a source when called with files only', function (): void {
        $pending = makeCollate()->merge('doc.pdf', 'b.pdf');

        expect(getProperty($pending, 'source'))->toBeNull();
    });
});

it('can use macros on PendingCollate', function (): void {
    PendingCollate::macro('testMacro', fn (): string => 'macro-called');

    $pending = makeCollate()->open('doc.pdf');

    expect($pending->testMacro())->toBe('macro-called');
});
