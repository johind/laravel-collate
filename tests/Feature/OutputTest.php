<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    if (! qpdfAvailable()) {
        test()->skip('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::fake('s3');
    Storage::put('input.pdf', file_get_contents(fixturePath('single-page.pdf')));
    Storage::put('multi.pdf', file_get_contents(fixturePath('multi-page.pdf')));
    Storage::put('twelve.pdf', file_get_contents(fixturePath('twelve-page.pdf')));
    Storage::put('encrypted.pdf', file_get_contents(fixturePath('encrypted.pdf')));
});

describe('save()', function () {
    it('writes the processed file to the configured disk', function () {
        makeCollate()->open('input.pdf')->save('output.pdf');

        expect(Storage::exists('output.pdf'))->toBeTrue();
    });

    it('with an explicit disk writes to that disk instead', function () {
        makeCollate()->open('input.pdf')->save('output.pdf', disk: 's3');

        expect(Storage::disk('s3')->exists('output.pdf'))->toBeTrue()
            ->and(Storage::disk('local')->exists('output.pdf'))->toBeFalse();
    });

    it('does not leave a temp file behind after saving', function () {
        $tempDir = sys_get_temp_dir().'/collate-tests';
        $beforeCount = is_dir($tempDir) ? count(glob($tempDir.'/*.pdf')) : 0;

        makeCollate()->open('input.pdf')->save('output.pdf');

        $afterCount = is_dir($tempDir) ? count(glob($tempDir.'/*.pdf')) : 0;

        expect($afterCount)->toBe($beforeCount);
    });
});

describe('content()', function () {
    it('returns a non-empty string', function () {
        $content = makeCollate()->open('input.pdf')->content();

        expect($content)->toBeString()->not->toBeEmpty();
    });

    it('output starts with the PDF magic bytes', function () {
        $content = makeCollate()->open('input.pdf')->content();

        expect(substr($content, 0, 4))->toBe('%PDF');
    });
});

describe('download()', function () {
    it('returns a StreamedResponse', function () {
        $response = makeCollate()->open('input.pdf')->download('test.pdf');

        expect($response)->toBeInstanceOf(StreamedResponse::class);
    });

    it('sets the Content-Disposition header to attachment', function () {
        $response = makeCollate()->open('input.pdf')->download('test.pdf');

        expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    });

    it('includes the given filename in the Content-Disposition header', function () {
        $response = makeCollate()->open('input.pdf')->download('my-invoice.pdf');

        expect($response->headers->get('Content-Disposition'))->toContain('my-invoice.pdf');
    });

    it('sets the Content-Type to application/pdf', function () {
        $response = makeCollate()->open('input.pdf')->download();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    });
});

describe('stream()', function () {
    it('returns a StreamedResponse', function () {
        $response = makeCollate()->open('input.pdf')->stream('test.pdf');

        expect($response)->toBeInstanceOf(StreamedResponse::class);
    });

    it('sets the Content-Disposition header to inline', function () {
        $response = makeCollate()->open('input.pdf')->stream('test.pdf');

        expect($response->headers->get('Content-Disposition'))->toContain('inline');
    });
});

describe('split()', function () {
    it('returns a Collection', function () {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        expect($paths)->toBeInstanceOf(Collection::class);
    });

    it('returns a non-empty collection', function () {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        expect($paths)->not->toBeEmpty();
    });

    it('replaces the {page} placeholder with the correct page number', function () {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        $paths->each(fn ($path) => expect($path)->toMatch('/pages\/page-\d+\.pdf/'));
    });

    it('saves every split page to disk', function () {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        $paths->each(fn ($path) => expect(Storage::exists($path))->toBeTrue());
    });

    it('without {page} in the path, all entries point to the same destination', function () {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page.pdf');

        expect($paths->unique())->toHaveCount(1);
    });

    it('respects page selection applied before splitting', function () {
        $paths = makeCollate()->open('multi.pdf')
            ->onlyPages('1')
            ->split('pages/page-{page}.pdf');

        expect($paths)->toHaveCount(1);
    });

    it('correctly splits a PDF with more than 10 pages', function () {
        $paths = makeCollate()->open('twelve.pdf')->split('pages/page-{page}.pdf');

        expect($paths)->toHaveCount(12);
        $paths->each(fn ($path) => expect(Storage::exists($path))->toBeTrue());
    });

    it('successfully splits an encrypted document', function () {
        $paths = makeCollate()->open('multi.pdf')
            ->encrypt('user', 'owner')
            ->split('split-enc/page-{page}.pdf');

        expect($paths)->not->toBeEmpty();
        $paths->each(fn ($path) => expect(Storage::exists($path))->toBeTrue());
    });
});

describe('decrypt()', function () {
    it('successfully processes a password-protected document', function () {
        makeCollate()->open('encrypted.pdf')->decrypt('test')->save('decrypted.pdf');

        expect(Storage::exists('decrypted.pdf'))->toBeTrue();
    });

    it('processes an encrypted source with additions', function () {
        makeCollate()->open('encrypted.pdf')
            ->decrypt('test')
            ->addPage('input.pdf')
            ->save('merged.pdf');

        expect(Storage::exists('merged.pdf'))->toBeTrue();
    });
});
