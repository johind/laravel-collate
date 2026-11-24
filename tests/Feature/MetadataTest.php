<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Johind\Collate\PdfMetadata;

beforeEach(function (): void {
    if (! qpdfAvailable()) {
        skipTest('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::put('doc.pdf', fixtureContents('single-page.pdf'));
    Storage::put('multi.pdf', fixtureContents('multi-page.pdf'));
});

describe('pageCount()', function (): void {
    it('returns 1 for the single-page fixture', function (): void {
        $count = makeCollate()->inspect('doc.pdf')->pageCount();

        expect($count)->toBe(1);
    });

    it('returns 5 for the multi-page fixture', function (): void {
        $count = makeCollate()->inspect('multi.pdf')->pageCount();

        expect($count)->toBe(5);
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

    it('counts correctly with :odd modifier on onlyPages()', function (): void {
        // multi.pdf has 5 pages, odd pages of 1-5 are 1,3,5 → 3 pages
        $count = makeCollate()->open('multi.pdf')
            ->onlyPages('1-z:odd')
            ->pageCount();

        expect($count)->toBe(3);
    });

    it('counts correctly with :even modifier on onlyPages()', function (): void {
        // multi.pdf has 5 pages, even pages of 1-5 are 2,4 → 2 pages
        $count = makeCollate()->open('multi.pdf')
            ->onlyPages('1-z:even')
            ->pageCount();

        expect($count)->toBe(2);
    });

    it('counts correctly with :odd modifier on addPages()', function (): void {
        // doc.pdf (1 page) + odd pages of multi.pdf 1-5 (1,3,5) → 4 pages
        $count = makeCollate()->open('doc.pdf')
            ->addPages('multi.pdf', '1-z:odd')
            ->pageCount();

        expect($count)->toBe(4);
    });

    it('counts correctly with removePages() exclusions', function (): void {
        // multi.pdf has 5 pages, remove page 1 → 4 pages
        $count = makeCollate()->open('multi.pdf')
            ->removePages('1')
            ->pageCount();

        expect($count)->toBe(4);
    });

    it('counts correctly with :odd exclusions from removePages()', function (): void {
        // multi.pdf has 5 pages, remove odd-positioned pages (1,3,5) → keeps 2,4 → 2 pages.
        $count = makeCollate()->open('multi.pdf')
            ->removePages('1-z:odd')
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

describe('metadata() string decoding', function (): void {
    it('decodes binary-encoded (b:) info strings', function (string $encoded, string $expected): void {
        $pending = pendingWithQpdfJson([
            'qpdf' => [
                ['jsonversion' => 2],
                [
                    'trailer' => ['value' => ['/Info' => '9 0 R']],
                    'obj:9 0 R' => ['value' => ['/Title' => $encoded, '/Author' => 'u:Plain']],
                ],
            ],
        ]);

        $meta = $pending->metadata();

        expect($meta->title)->toBe($expected)
            ->and($meta->author)->toBe('Plain');
    })->with([
        'valid UTF-8 bytes' => ['b:c3a974c3a9', 'été'],
        'UTF-8 with byte order mark' => ['b:efbbbfc3a974c3a9', 'été'],
        'PDFDocEncoding, Latin-1 range' => ['b:e974e9', 'été'],
        'PDFDocEncoding, bytes that differ from Latin-1' => ['b:8020a0', '• €'],
        'odd-length hex is kept as-is' => ['b:abc', 'b:abc'],
        'non-hex is kept as-is' => ['b:zz', 'b:zz'],
    ]);

    // qpdf decodes UTF-16 itself and reports it as "u:", so this uses
    // single-byte text, which qpdf does report as "b:".
    it('decodes binary strings written to a real PDF', function (): void {
        $path = Storage::path('doc.pdf');
        $json = qpdfJson($path);
        $infoRef = qpdfValue(qpdfObjects($json), 'trailer', 'value', '/Info');
        assert(is_string($infoRef));

        file_put_contents($path.'.json', jsonString(['qpdf' => [
            ['jsonversion' => 2, 'pushedinheritedpageresources' => false],
            ['obj:'.$infoRef => ['value' => ['/Title' => 'b:e974e9']]],
        ]]));
        Illuminate\Support\Facades\Process::run(['qpdf', $path, '--update-from-json='.$path.'.json', '--replace-input'])->throw();

        $objects = qpdfObjects(qpdfJson($path));
        $infoRef = qpdfValue($objects, 'trailer', 'value', '/Info');
        assert(is_string($infoRef));

        expect(qpdfValue($objects, 'obj:'.$infoRef, 'value', '/Title'))->toBe('b:e974e9')
            ->and(makeCollate()->inspect('doc.pdf')->metadata()->title)->toBe('été');
    });
});
