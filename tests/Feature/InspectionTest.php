<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Johind\Collate\PageSize;

beforeEach(function (): void {
    if (! qpdfAvailable()) {
        test()->skip('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
    Storage::put('multi.pdf', file_get_contents(fixturePath('multi-page.pdf')));
    Storage::put('encrypted.pdf', file_get_contents(fixturePath('encrypted.pdf')));
    Storage::put('annotated.pdf', file_get_contents(fixturePath('annotated.pdf')));
});

describe('isEncrypted()', function (): void {
    it('returns false for an unencrypted document', function (): void {
        expect(makeCollate()->inspect('doc.pdf')->isEncrypted())->toBeFalse();
    });

    it('returns true for an encrypted document', function (): void {
        expect(makeCollate()->inspect('encrypted.pdf')->isEncrypted())->toBeTrue();
    });

    it('returns true for a document encrypted at runtime', function (): void {
        makeCollate()->open('doc.pdf')
            ->encrypt('secret')
            ->save('enc.pdf');

        $inspected = makeCollate()->inspect('enc.pdf');

        expect($inspected->isEncrypted())->toBeTrue()
            ->and($inspected->hasPassword())->toBeTrue();
    });
});

describe('hasPassword()', function (): void {
    it('returns false for an unencrypted document', function (): void {
        expect(makeCollate()->inspect('doc.pdf')->hasPassword())->toBeFalse();
    });

    it('returns true for an encrypted document', function (): void {
        expect(makeCollate()->inspect('encrypted.pdf')->hasPassword())->toBeTrue();
    });
});

describe('isLinearized()', function (): void {
    it('returns false for a non-linearized document', function (): void {
        expect(makeCollate()->inspect('doc.pdf')->isLinearized())->toBeFalse();
    });

    it('returns true for a linearized document', function (): void {
        makeCollate()->open('doc.pdf')
            ->linearize()
            ->save('linearized.pdf');

        expect(makeCollate()->inspect('linearized.pdf')->isLinearized())->toBeTrue();
    });
});

describe('pdfVersion()', function (): void {
    it('returns a version string', function (): void {
        $version = makeCollate()->inspect('doc.pdf')->pdfVersion();

        expect($version)->toBeString()
            ->and($version)->toMatch('/^\d+\.\d+$/');
    });

    it('returns 1.7 for the single-page fixture', function (): void {
        expect(makeCollate()->inspect('doc.pdf')->pdfVersion())->toBe('1.7');
    });

    it('can inspect a decrypted encrypted document', function (): void {
        $inspected = makeCollate()->inspect('encrypted.pdf')->decrypt('test');

        expect($inspected->pdfVersion())->toMatch('/^\d+\.\d+$/')
            ->and($inspected->pageSize())->toBeInstanceOf(PageSize::class);
    });
});

describe('pageSize()', function (): void {
    it('returns a PageSize instance', function (): void {
        $size = makeCollate()->inspect('doc.pdf')->pageSize();

        expect($size)->toBeInstanceOf(PageSize::class);
    });

    it('returns US Letter dimensions for the single-page fixture', function (): void {
        $size = makeCollate()->inspect('doc.pdf')->pageSize(1);

        expect($size->width)->toBe(612.0)
            ->and($size->height)->toBe(792.0)
            ->and($size->userUnit)->toBe(1.0);
    });

    it('defaults to page 1', function (): void {
        $size = makeCollate()->inspect('doc.pdf')->pageSize();

        expect($size->width)->toBe(612.0)
            ->and($size->height)->toBe(792.0);
    });

    it('can read a specific page from a multi-page document', function (): void {
        $size = makeCollate()->inspect('multi.pdf')->pageSize(3);

        expect($size)->toBeInstanceOf(PageSize::class)
            ->and($size->width)->toBeGreaterThan(0)
            ->and($size->height)->toBeGreaterThan(0);
    });

    it('throws for a page that does not exist', function (): void {
        expect(fn () => makeCollate()->inspect('doc.pdf')->pageSize(99))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('withoutMetadata()', function (): void {
    it('strips all metadata fields from the output', function (): void {
        makeCollate()->open('doc.pdf')
            ->withMetadata(
                title: 'Test Title',
                author: 'Test Author',
                subject: 'Test Subject',
            )
            ->save('with-meta.pdf');

        makeCollate()->open('with-meta.pdf')
            ->withoutMetadata()
            ->save('stripped.pdf');

        $meta = makeCollate()->inspect('stripped.pdf')->metadata();

        expect($meta->title)->toBeNull()
            ->and($meta->author)->toBeNull()
            ->and($meta->subject)->toBeNull();
    });

    it('removes the /Info trailer entry and XMP metadata references from the output PDF', function (): void {
        $original = storageQpdfJson('annotated.pdf');
        expect(arrayContainsKeyRecursive($original, '/Info'))->toBeTrue()
            ->and(arrayContainsKeyRecursive($original, '/Metadata'))->toBeTrue();

        makeCollate()->open('annotated.pdf')
            ->withoutMetadata()
            ->save('stripped.pdf');

        $stripped = storageQpdfJson('stripped.pdf');
        /** @var array{qpdf: array{1: array{trailer: array{value: array<string, mixed>}}}} $stripped */
        $trailerValue = $stripped['qpdf'][1]['trailer']['value'];

        expect(array_key_exists('/Info', $trailerValue))->toBeFalse()
            ->and(arrayContainsKeyRecursive($stripped, '/Metadata'))->toBeFalse();
    });

    it('can add metadata back after the /Info dictionary has been removed', function (): void {
        makeCollate()->open('doc.pdf')
            ->withoutMetadata()
            ->save('without-info.pdf');

        makeCollate()->open('without-info.pdf')
            ->withMetadata(title: 'Restored Title', author: 'Restored Author')
            ->save('restored-meta.pdf');

        $meta = makeCollate()->inspect('restored-meta.pdf')->metadata();

        expect($meta->title)->toBe('Restored Title')
            ->and($meta->author)->toBe('Restored Author');
    });
});

describe('optimize()', function (): void {
    it('produces a file that is not larger than the original', function (): void {
        makeCollate()->open('doc.pdf')
            ->optimize()
            ->save('optimized.pdf');

        $originalSize = mb_strlen(Storage::get('doc.pdf'), '8bit');
        $optimizedSize = mb_strlen(Storage::get('optimized.pdf'), '8bit');

        expect($optimizedSize)->toBeLessThanOrEqual($originalSize);
    });

    it('can be combined with other operations', function (): void {
        makeCollate()->open('doc.pdf')
            ->rotate(90)
            ->optimize()
            ->linearize()
            ->save('combined.pdf');

        $content = Storage::get('combined.pdf');

        expect(mb_substr($content, 0, 4))->toBe('%PDF')
            ->and($content)->toContain('/Linearized');
    });
});
