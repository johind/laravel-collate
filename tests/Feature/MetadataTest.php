<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\PdfMetadata;

beforeEach(function () {
    if (! qpdfAvailable()) {
        test()->skip('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
    Storage::put('multi.pdf', file_get_contents(fixturePath('multi-page.pdf')));
});

describe('pageCount()', function () {
    it('returns 1 for the single-page fixture', function () {
        $count = makeCollate()->inspect('doc.pdf')->pageCount();

        expect($count)->toBe(1);
    });

    it('returns more than 1 for the multi-page fixture', function () {
        $count = makeCollate()->inspect('multi.pdf')->pageCount();

        expect($count)->toBeGreaterThan(1);
    });
});

describe('metadata()', function () {
    it('returns a PdfMetadata instance', function () {
        $meta = makeCollate()->inspect('doc.pdf')->metadata();

        expect($meta)->toBeInstanceOf(PdfMetadata::class);
    });

    it('round-trips metadata written by withMetadata()', function () {
        makeCollate()->open('doc.pdf')
            ->withMetadata(title: 'Test Title', author: 'Test Author')
            ->save('with-meta.pdf');

        $meta = makeCollate()->inspect('with-meta.pdf')->metadata();

        expect($meta->title)->toBe('Test Title')
            ->and($meta->author)->toBe('Test Author');
    });
});
