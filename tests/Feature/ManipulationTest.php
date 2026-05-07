<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    if (! qpdfAvailable()) {
        test()->skip('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::put('input.pdf', file_get_contents(fixturePath('single-page.pdf')));
    Storage::put('other.pdf', file_get_contents(fixturePath('single-page.pdf')));
    Storage::put('annotated.pdf', file_get_contents(fixturePath('annotated.pdf')));
});

it('rotates pages correctly', function (): void {
    makeCollate()->open('input.pdf')
        ->rotate(90)
        ->save('rotated.pdf');

    $pageValues = qpdfPageValues(storageQpdfJson('rotated.pdf'));

    expect($pageValues['/Rotate'] ?? null)->toBe(90);
});

it('overlays a PDF correctly', function (): void {
    makeCollate()->open('input.pdf')
        ->overlay('other.pdf')
        ->save('overlaid.pdf');

    $originalPage = qpdfPageValues(storageQpdfJson('input.pdf'));
    $overlaidPage = qpdfPageValues(storageQpdfJson('overlaid.pdf'));

    expect($originalPage['/Resources']['/XObject'] ?? null)->toBeNull()
        ->and($overlaidPage['/Resources']['/XObject'] ?? null)->toBeArray()->not->toBeEmpty();
});

it('underlays a PDF correctly', function (): void {
    makeCollate()->open('input.pdf')
        ->underlay('other.pdf')
        ->save('underlaid.pdf');

    $originalPage = qpdfPageValues(storageQpdfJson('input.pdf'));
    $underlaidPage = qpdfPageValues(storageQpdfJson('underlaid.pdf'));

    expect($originalPage['/Resources']['/XObject'] ?? null)->toBeNull()
        ->and($underlaidPage['/Resources']['/XObject'] ?? null)->toBeArray()->not->toBeEmpty();
});

it('linearizes the output PDF', function (): void {
    makeCollate()->open('input.pdf')
        ->linearize()
        ->save('linearized.pdf');

    $linearized = Storage::get('linearized.pdf');

    expect(mb_substr($linearized, 0, 4))->toBe('%PDF')
        ->and($linearized)->toContain('/Linearized');
});

it('flattens annotations and form fields', function (): void {
    $originalJson = storageQpdfJson('annotated.pdf');
    expect($originalJson['qpdf'][1]['trailer']['value']['/Root'] ?? null)->toBeString()
        ->and(qpdfPageValues($originalJson)['/Annots'] ?? null)->toBeArray()->not->toBeEmpty();

    makeCollate()->open('annotated.pdf')
        ->flatten()
        ->save('flattened.pdf');

    $flattenedJson = storageQpdfJson('flattened.pdf');
    $flattenedPage = qpdfPageValues($flattenedJson);

    expect($flattenedPage)->not->toHaveKey('/Annots')
        ->and(json_encode($flattenedJson))->not->toContain('/AcroForm')
        ->and(json_encode($flattenedJson))->not->toContain('/Widget');
});

it('combines multiple manipulations', function (): void {
    makeCollate()->open('input.pdf')
        ->rotate(180)
        ->overlay('other.pdf')
        ->linearize()
        ->save('combined.pdf');

    $combined = Storage::get('combined.pdf');
    $pageValues = qpdfPageValues(storageQpdfJson('combined.pdf'));

    expect(mb_substr($combined, 0, 4))->toBe('%PDF')
        ->and($pageValues['/Rotate'] ?? null)->toBe(180)
        ->and($pageValues['/Resources']['/XObject'] ?? null)->toBeArray()->not->toBeEmpty()
        ->and($combined)->toContain('/Linearized');
});
