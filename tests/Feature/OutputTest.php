<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function (): void {
    if (! qpdfAvailable()) {
        skipTest('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::fake('s3');
    Storage::put('input.pdf', fixtureContents('single-page.pdf'));
    Storage::put('multi.pdf', fixtureContents('multi-page.pdf'));
    Storage::put('twelve.pdf', fixtureContents('twelve-page.pdf'));
    Storage::put('encrypted.pdf', fixtureContents('encrypted.pdf'));
});

describe('save()', function (): void {
    it('writes the processed file to the configured disk', function (): void {
        makeCollate()->open('input.pdf')->save('output.pdf');

        expect(Storage::exists('output.pdf'))->toBeTrue();
    });

    it('with an explicit disk writes to that disk instead', function (): void {
        Storage::fake('s3');

        makeCollate()->open('input.pdf')->toDisk('s3')->save('output.pdf');

        expect(Storage::disk('s3')->exists('output.pdf'))->toBeTrue()
            ->and(Storage::disk('local')->exists('output.pdf'))->toBeFalse();
    });

    it('cleans up the temp file after the builder is destroyed', function (): void {
        $pending = makeCollate()->open('input.pdf');
        $pending->save('output.pdf');

        $processedPath = getStringProperty($pending, 'processedPath');
        expect($processedPath)->not->toBeNull()
            ->and(file_exists($processedPath))->toBeTrue();

        unset($pending);

        expect(file_exists($processedPath))->toBeFalse();
    });

    it('saves the expected page count when removing odd pages', function (): void {
        makeCollate()->open('multi.pdf')
            ->removePages('1-z:odd')
            ->save('odd-removed.pdf');

        $count = makeCollate()->open('odd-removed.pdf')->pageCount();

        expect($count)->toBe(2);
    });
});

describe('memoization', function (): void {
    it('reuses the processed file across multiple output calls', function (): void {
        $pending = makeCollate()->open('input.pdf');

        $content1 = $pending->content();
        $processedPath1 = getStringProperty($pending, 'processedPath');

        $content2 = $pending->content();
        $processedPath2 = getStringProperty($pending, 'processedPath');

        expect($processedPath1)->not->toBeNull()
            ->and($processedPath2)->toBe($processedPath1)
            ->and($content1)->toBe($content2);
    });

    it('clears the memoized result when a mutation occurs', function (): void {
        Storage::put('other.pdf', fixtureContents('single-page.pdf'));

        $pending = makeCollate()->open('input.pdf');
        $pending->content();

        $firstProcessedPath = getStringProperty($pending, 'processedPath');
        expect($firstProcessedPath)->not->toBeNull();

        $pending->addPages('other.pdf');

        expect(getProperty($pending, 'processedPath'))->toBeNull()
            ->and(file_exists($firstProcessedPath))->toBeFalse();
    });

    it('cleans up the memoized file on destruction', function (): void {
        $pending = makeCollate()->open('input.pdf');
        $pending->content();

        $processedPath = getStringProperty($pending, 'processedPath');
        expect(file_exists($processedPath))->toBeTrue();

        unset($pending);

        expect(file_exists($processedPath))->toBeFalse();
    });
});

describe('content()', function (): void {
    it('output starts with the PDF magic bytes', function (): void {
        $content = makeCollate()->open('input.pdf')->content();

        expect(mb_substr($content, 0, 4))->toBe('%PDF');
    });
});

describe('download()', function (): void {
    it('returns a StreamedResponse', function (): void {
        $response = makeCollate()->open('input.pdf')->download('test.pdf');

        expect($response)->toBeInstanceOf(StreamedResponse::class);
    });

    it('sets the Content-Disposition header to attachment', function (): void {
        $response = makeCollate()->open('input.pdf')->download('test.pdf');

        expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    });

    it('includes the given filename in the Content-Disposition header', function (): void {
        $response = makeCollate()->open('input.pdf')->download('my-invoice.pdf');

        expect($response->headers->get('Content-Disposition'))->toContain('my-invoice.pdf');
    });

    it('sets the Content-Type to application/pdf', function (): void {
        $response = makeCollate()->open('input.pdf')->download();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    });
});

describe('stream()', function (): void {
    it('returns a StreamedResponse', function (): void {
        $response = makeCollate()->open('input.pdf')->stream('test.pdf');

        expect($response)->toBeInstanceOf(StreamedResponse::class);
    });

    it('sets the Content-Disposition header to inline', function (): void {
        $response = makeCollate()->open('input.pdf')->stream('test.pdf');

        expect($response->headers->get('Content-Disposition'))->toContain('inline');
    });
});

describe('Content-Disposition encoding', function (): void {
    it('escapes quotes so the filename cannot inject header parameters', function (): void {
        $response = makeCollate()->open('input.pdf')->download('a"; filename="evil.html');

        expect($response->headers->get('Content-Disposition'))
            ->toBe('attachment; filename="a\"; filename=\"evil.html"');
    });

    it('strips control characters from the filename', function (): void {
        $response = makeCollate()->open('input.pdf')->download("in\r\nvoice.pdf");

        expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=invoice.pdf');
    });

    it('keeps filenames that are not valid UTF-8', function (): void {
        $response = makeCollate()->open('input.pdf')->download("Bericht_\xFC.pdf");

        expect($response->headers->get('Content-Disposition'))
            ->toBe("attachment; filename=Bericht_.pdf; filename*=utf-8''Bericht_%FC.pdf");
    });

    it('rejects unknown dispositions', function (): void {
        expect(fn (): StreamedResponse => makeCollate()->open('input.pdf')->toResponse(request(), 'a.pdf', 'evil'))
            ->toThrow(InvalidArgumentException::class, "disposition must be 'inline' or 'attachment'");
    });

    it('streams the PDF contents', function (): void {
        $response = makeCollate()->open('input.pdf')->download('a.pdf');

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        expect(mb_substr($body, 0, 4))->toBe('%PDF');
    });
});

describe('split()', function (): void {
    it('replaces the {page} placeholder with the correct page number', function (): void {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        $paths->each(fn ($path) => expect($path)->toMatch('/pages\/page-\d+\.pdf/'));
    });

    it('saves every split page to disk', function (): void {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        $paths->each(fn ($path) => expect(Storage::exists($path))->toBeTrue());
    });

    it('without {page} in the path, appends the page number before the extension', function (): void {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page.pdf');

        expect($paths->all())->toBe([
            'pages/page-1.pdf',
            'pages/page-2.pdf',
            'pages/page-3.pdf',
            'pages/page-4.pdf',
            'pages/page-5.pdf',
        ])
            ->and(Storage::exists('pages/page.pdf'))->toBeFalse();

        $paths->each(fn (string $path) => expect(mb_substr(storageContents($path), 0, 4))->toBe('%PDF'));
    });

    it('without {page} or an extension, appends the page number to the path', function (): void {
        $paths = makeCollate()->open('multi.pdf')->onlyPages('1-2')->split('pages/page');

        expect($paths->all())->toBe(['pages/page-1', 'pages/page-2']);
    });

    it('respects page selection applied before splitting', function (): void {
        $paths = makeCollate()->open('multi.pdf')
            ->onlyPages('1')
            ->split('pages/page-{page}.pdf');

        expect($paths)->toHaveCount(1);
    });

    it('respects removePages() with odd exclusions before splitting', function (): void {
        $paths = makeCollate()->open('multi.pdf')
            ->removePages('1-z:odd')
            ->split('pages/odd-removed-{page}.pdf');

        expect($paths)->toHaveCount(2);
        $paths->each(fn ($path) => expect(Storage::exists($path))->toBeTrue());
    });

    it('correctly splits a PDF with more than 10 pages', function (): void {
        $paths = makeCollate()->open('twelve.pdf')->split('pages/page-{page}.pdf');

        expect($paths)->toHaveCount(12);
        $paths->each(fn ($path) => expect(Storage::exists($path))->toBeTrue());
    });

    it('respects toDisk() when writing split pages', function (): void {
        $paths = makeCollate()->open('multi.pdf')
            ->toDisk('s3')
            ->split('pages/page-{page}.pdf');

        expect($paths)->not->toBeEmpty();
        $paths->each(fn ($path) => expect(Storage::disk('s3')->exists($path))->toBeTrue());
        $paths->each(fn ($path) => expect(Storage::disk('local')->exists($path))->toBeFalse());
    });

    it('successfully splits an encrypted document', function (): void {
        $pageCount = makeCollate()->inspect('multi.pdf')->pageCount();

        $paths = makeCollate()->open('multi.pdf')
            ->encrypt('user', 'owner')
            ->split('split-enc/page-{page}.pdf');

        expect($paths)->toHaveCount($pageCount);
        $paths->each(fn (string $path) => expect(Storage::exists($path))->toBeTrue()
            ->and(mb_substr(storageContents($path), 0, 4))->toBe('%PDF'));
    });
});

describe('decrypt()', function (): void {
    it('successfully processes a password-protected document', function (): void {
        makeCollate()->open('encrypted.pdf')->decrypt('test')->save('decrypted.pdf');

        $decrypted = storageContents('decrypted.pdf');

        expect(mb_substr($decrypted, 0, 4))->toBe('%PDF');

        // Verify the output can be opened without a password
        $pageCount = makeCollate()->inspect('decrypted.pdf')->pageCount();
        expect($pageCount)->toBeGreaterThan(0);
    });

    it('processes an encrypted source with additions', function (): void {
        $originalPageCount = makeCollate()->open('encrypted.pdf')->decrypt('test')->pageCount();

        makeCollate()->open('encrypted.pdf')
            ->decrypt('test')
            ->addPages('input.pdf')
            ->save('merged.pdf');

        $mergedPageCount = makeCollate()->inspect('merged.pdf')->pageCount();

        expect(mb_substr(storageContents('merged.pdf'), 0, 4))->toBe('%PDF')
            ->and($mergedPageCount)->toBe($originalPageCount + 1);
    });
});
