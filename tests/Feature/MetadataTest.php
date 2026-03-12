<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Johind\Collate\PdfMetadata;

beforeEach(function (): void {
    if (! qpdfAvailable()) {
        test()->skip('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
    Storage::put('multi.pdf', file_get_contents(fixturePath('multi-page.pdf')));
});

describe('pageCount()', function (): void {
    it('returns 1 for the single-page fixture', function (): void {
        $count = makeCollate()->inspect('doc.pdf')->pageCount();

        expect($count)->toBe(1);
    });

    it('returns more than 1 for the multi-page fixture', function (): void {
        $count = makeCollate()->inspect('multi.pdf')->pageCount();

        expect($count)->toBeGreaterThan(1);
    });

    it('correctly sums up page counts for merged documents', function (): void {
        $count = makeCollate()->merge('doc.pdf', 'multi.pdf')->pageCount();

        // doc.pdf is 1 page, multi.pdf is 5 pages.
        expect($count)->toBe(6);
    });

    it('respects page selections in pageCount()', function (): void {
        $count = makeCollate()->open('multi.pdf')
            ->onlyPages('1')
            ->addPage('multi.pdf', 2)
            ->pageCount();

        expect($count)->toBe(2);
    });

    it('memoizes the total page count across repeated calls', function (): void {
        $pending = makeCollate()->inspect('multi.pdf');

        $first = $pending->pageCount();
        $second = $pending->pageCount();

        expect($first)->toBe($second)
            ->and(getProperty($pending, 'memoizedTotalPageCount'))->toBe($first);
    });

    it('invalidates the memoized page count after a mutation', function (): void {
        $pending = makeCollate()->open('multi.pdf');
        $pending->pageCount();

        expect(getProperty($pending, 'memoizedTotalPageCount'))->not->toBeNull();

        $pending->addPages('doc.pdf');

        expect(getProperty($pending, 'memoizedTotalPageCount'))->toBeNull();
    });

    it('caches individual file page counts', function (): void {
        $pending = makeCollate()->inspect('multi.pdf');
        $pending->pageCount();

        $cache = getProperty($pending, 'filePageCountCache');
        expect($cache)->not->toBeEmpty();
    });

    it('handles complex qpdf ranges in pageCount()', function (): void {
        // multi.pdf has 5 pages. z-1 reversed = 5 pages.
        $count = makeCollate()->open('multi.pdf')->onlyPages('z-1')->pageCount();
        expect($count)->toBe(5);

        // Individual z = 1 page.
        $count = makeCollate()->open('multi.pdf')->onlyPages('z')->pageCount();
        expect($count)->toBe(1);

        // Mix of z and numbers
        $count = makeCollate()->open('multi.pdf')->onlyPages('1,z,2-4')->pageCount();
        expect($count)->toBe(5); // 1, 5, 2, 3, 4
    });
});

describe('metadata()', function (): void {
    it('returns a PdfMetadata instance', function (): void {
        $meta = makeCollate()->inspect('doc.pdf')->metadata();

        expect($meta)->toBeInstanceOf(PdfMetadata::class);
    });

    it('round-trips all metadata fields written by withMetadata()', function (): void {
        makeCollate()->open('doc.pdf')
            ->withMetadata(
                title: 'Test Title',
                author: 'Test Author',
                subject: 'Test Subject',
                keywords: 'test, keywords',
                creator: 'Test Creator',
                producer: 'Test Producer',
                creationDate: 'D:20250101000000Z',
                modDate: 'D:20250101000000Z',
            )
            ->save('with-meta.pdf');

        $meta = makeCollate()->inspect('with-meta.pdf')->metadata();

        expect($meta->title)->toBe('Test Title')
            ->and($meta->author)->toBe('Test Author')
            ->and($meta->subject)->toBe('Test Subject')
            ->and($meta->keywords)->toBe('test, keywords')
            ->and($meta->creator)->toBe('Test Creator')
            ->and($meta->producer)->toBe('Test Producer')
            ->and($meta->creationDate)->toBe('D:20250101000000Z')
            ->and($meta->modDate)->toBe('D:20250101000000Z');
    });

    it('round-trips metadata on an encrypted document using owner password', function (): void {
        makeCollate()->open('doc.pdf')
            ->encrypt('user', 'owner')
            ->withMetadata(title: 'Encrypted Doc', author: 'Test')
            ->save('enc-meta.pdf');

        $meta = makeCollate()->open('enc-meta.pdf')
            ->decrypt('owner')
            ->metadata();

        expect($meta->title)->toBe('Encrypted Doc')
            ->and($meta->author)->toBe('Test');
    });
});
