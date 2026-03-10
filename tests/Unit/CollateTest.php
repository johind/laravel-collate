<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Collate;
use Johind\Collate\PendingCollate;

beforeEach(function () {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

it('stores the binary path', function () {
    $collate = new Collate('/usr/bin/qpdf');

    expect($collate->binaryPath())->toBe('/usr/bin/qpdf');
});

it('stores the disk name', function () {
    $collate = new Collate('qpdf', 's3');

    expect($collate->diskName())->toBe('s3');
});

it('stores the temp directory', function () {
    $collate = new Collate('qpdf', null, '/tmp/collate');

    expect($collate->tempDirectory())->toBe('/tmp/collate');
});

describe('disk()', function () {
    it('returns a new instance', function () {
        $original = makeCollate();
        $cloned = $original->disk('s3');

        expect($cloned)->not->toBe($original);
    });

    it('does not mutate the original instance', function () {
        $original = makeCollate('local');
        $original->disk('s3');

        expect($original->diskName())->toBe('local');
    });

    it('sets the disk name on the cloned instance', function () {
        $cloned = makeCollate('local')->disk('s3');

        expect($cloned->diskName())->toBe('s3');
    });
});

describe('open()', function () {
    it('returns a PendingCollate', function () {
        expect(makeCollate()->open('doc.pdf'))->toBeInstanceOf(PendingCollate::class);
    });

    it('sets the source file on the builder', function () {
        $pending = makeCollate()->open('doc.pdf');

        expect(getProperty($pending, 'source'))->not->toBeNull();
    });
});

describe('inspect()', function () {
    it('returns a PendingCollate', function () {
        expect(makeCollate()->inspect('doc.pdf'))->toBeInstanceOf(PendingCollate::class);
    });

    it('sets the source file on the builder', function () {
        $pending = makeCollate()->inspect('doc.pdf');

        expect(getProperty($pending, 'source'))->not->toBeNull();
    });
});

describe('merge()', function () {
    beforeEach(function () {
        Storage::put('b.pdf', file_get_contents(fixturePath('single-page.pdf')));
    });

    it('returns a PendingCollate', function () {
        expect(makeCollate()->merge('doc.pdf', 'b.pdf'))->toBeInstanceOf(PendingCollate::class);
    });

    it('populates additions for each file passed', function () {
        $pending = makeCollate()->merge('doc.pdf', 'b.pdf');

        expect(getProperty($pending, 'additions'))->toHaveCount(2);
    });

    it('closure receives and can mutate the pending instance', function () {
        $pending = makeCollate()->merge(function (PendingCollate $pdf) {
            $pdf->addPage('doc.pdf');
        });

        expect(getProperty($pending, 'additions'))->toHaveCount(1);
    });

    it('does not set a source when called with files only', function () {
        $pending = makeCollate()->merge('doc.pdf', 'b.pdf');

        expect(getProperty($pending, 'source'))->toBeNull();
    });
});
