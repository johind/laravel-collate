<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function (): void {
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

describe('save()', function (): void {
    it('writes the processed file to the configured disk', function (): void {
        makeCollate()->open('input.pdf')->save('output.pdf');

        expect(Storage::exists('output.pdf'))->toBeTrue();
    });

    it('with an explicit disk writes to that disk instead', function (): void {
        makeCollate()->open('input.pdf')->save('output.pdf', disk: 's3');

        expect(Storage::disk('s3')->exists('output.pdf'))->toBeTrue()
            ->and(Storage::disk('local')->exists('output.pdf'))->toBeFalse();
    });

    it('does not leave a temp file behind after saving', function (): void {
        $tempDir = sys_get_temp_dir().'/collate-tests';
        $beforeCount = is_dir($tempDir) ? count(glob($tempDir.'/*.pdf')) : 0;

        makeCollate()->open('input.pdf')->save('output.pdf');

        $afterCount = is_dir($tempDir) ? count(glob($tempDir.'/*.pdf')) : 0;

        expect($afterCount)->toBe($beforeCount);
    });
});

describe('content()', function (): void {
    it('returns a non-empty string', function (): void {
        $content = makeCollate()->open('input.pdf')->content();

        expect($content)->toBeString()->not->toBeEmpty();
    });

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

describe('split()', function (): void {
    it('returns a Collection', function (): void {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        expect($paths)->toBeInstanceOf(Collection::class);
    });

    it('returns a non-empty collection', function (): void {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        expect($paths)->not->toBeEmpty();
    });

    it('replaces the {page} placeholder with the correct page number', function (): void {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        $paths->each(fn ($path) => expect($path)->toMatch('/pages\/page-\d+\.pdf/'));
    });

    it('saves every split page to disk', function (): void {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page-{page}.pdf');

        $paths->each(fn ($path) => expect(Storage::exists($path))->toBeTrue());
    });

    it('without {page} in the path, all entries point to the same destination', function (): void {
        $paths = makeCollate()->open('multi.pdf')->split('pages/page.pdf');

        expect($paths->unique())->toHaveCount(1);
    });

    it('respects page selection applied before splitting', function (): void {
        $paths = makeCollate()->open('multi.pdf')
            ->onlyPages('1')
            ->split('pages/page-{page}.pdf');

        expect($paths)->toHaveCount(1);
    });

    it('correctly splits a PDF with more than 10 pages', function (): void {
        $paths = makeCollate()->open('twelve.pdf')->split('pages/page-{page}.pdf');

        expect($paths)->toHaveCount(12);
        $paths->each(fn ($path) => expect(Storage::exists($path))->toBeTrue());
    });

    it('successfully splits an encrypted document', function (): void {
        $paths = makeCollate()->open('multi.pdf')
            ->encrypt('user', 'owner')
            ->split('split-enc/page-{page}.pdf');

        expect($paths)->not->toBeEmpty();
        $paths->each(fn ($path) => expect(Storage::exists($path))->toBeTrue());
    });
});

describe('decrypt()', function (): void {
    it('successfully processes a password-protected document', function (): void {
        makeCollate()->open('encrypted.pdf')->decrypt('test')->save('decrypted.pdf');

        expect(Storage::exists('decrypted.pdf'))->toBeTrue();
    });

    it('processes an encrypted source with additions', function (): void {
        makeCollate()->open('encrypted.pdf')
            ->decrypt('test')
            ->addPages('input.pdf')
            ->save('merged.pdf');

        expect(Storage::exists('merged.pdf'))->toBeTrue();
    });
});
