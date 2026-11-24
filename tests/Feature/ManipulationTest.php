<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    if (! qpdfAvailable()) {
        skipTest('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::put('input.pdf', fixtureContents('single-page.pdf'));
    Storage::put('other.pdf', fixtureContents('single-page.pdf'));
    Storage::put('annotated.pdf', fixtureContents('annotated.pdf'));
});

it('rotates pages correctly', function (): void {
    makeCollate()->open('input.pdf')
        ->rotate(90)
        ->save('rotated.pdf');

    $pageValues = qpdfPageValues(storageQpdfJson('rotated.pdf'));

    expect($pageValues['/Rotate'] ?? null)->toBe(90);
});

it('rotates the pages selected with exclusions and relative page numbers', function (string $range, array $rotated): void {
    Storage::put('multi.pdf', fixtureContents('multi-page.pdf'));

    makeCollate()->open('multi.pdf')->rotate(90, $range)->save('rotated.pdf');

    $json = storageQpdfJson('rotated.pdf');
    $actual = array_values(array_filter(
        range(1, 5),
        fn (int $page): bool => (qpdfPageValues($json, $page)['/Rotate'] ?? 0) === 90,
    ));

    expect($actual)->toBe($rotated);
})->with([
    'exclusion' => ['1-z,x2', [1, 3, 4, 5]],
    'relative range' => ['r2-r1', [4, 5]],
]);

it('overlays a PDF correctly', function (): void {
    makeCollate()->open('input.pdf')
        ->overlay('other.pdf')
        ->save('overlaid.pdf');

    $originalPage = qpdfPageValues(storageQpdfJson('input.pdf'));
    $overlaidPage = qpdfPageValues(storageQpdfJson('overlaid.pdf'));
    $overlaidXObject = qpdfValue($overlaidPage, '/Resources', '/XObject');

    expect(qpdfValue($originalPage, '/Resources', '/XObject'))->toBeNull()
        ->and($overlaidXObject)->toBeArray();
    assert(is_array($overlaidXObject));
    expect(count($overlaidXObject))->toBeGreaterThan(0);
});

it('underlays a PDF correctly', function (): void {
    makeCollate()->open('input.pdf')
        ->underlay('other.pdf')
        ->save('underlaid.pdf');

    $originalPage = qpdfPageValues(storageQpdfJson('input.pdf'));
    $underlaidPage = qpdfPageValues(storageQpdfJson('underlaid.pdf'));
    $underlaidXObject = qpdfValue($underlaidPage, '/Resources', '/XObject');

    expect(qpdfValue($originalPage, '/Resources', '/XObject'))->toBeNull()
        ->and($underlaidXObject)->toBeArray();
    assert(is_array($underlaidXObject));
    expect(count($underlaidXObject))->toBeGreaterThan(0);
});

it('linearizes the output PDF', function (): void {
    makeCollate()->open('input.pdf')
        ->linearize()
        ->save('linearized.pdf');

    $linearized = storageContents('linearized.pdf');

    expect(mb_substr($linearized, 0, 4))->toBe('%PDF')
        ->and($linearized)->toContain('/Linearized');
});

it('flattens annotations and form fields', function (): void {
    $originalJson = storageQpdfJson('annotated.pdf');
    $originalAnnots = qpdfPageValues($originalJson)['/Annots'] ?? null;

    expect(qpdfValue(qpdfObjects($originalJson), 'trailer', 'value', '/Root'))->toBeString()
        ->and($originalAnnots)->toBeArray();
    assert(is_array($originalAnnots));
    expect(count($originalAnnots))->toBeGreaterThan(0);

    makeCollate()->open('annotated.pdf')
        ->flatten()
        ->save('flattened.pdf');

    $flattenedJson = storageQpdfJson('flattened.pdf');
    $flattenedPage = qpdfPageValues($flattenedJson);

    $flattenedJsonString = jsonString($flattenedJson);

    expect(array_key_exists('/Annots', $flattenedPage))->toBeFalse()
        ->and(str_contains($flattenedJsonString, '/AcroForm'))->toBeFalse()
        ->and(str_contains($flattenedJsonString, '/Widget'))->toBeFalse();
});

it('combines multiple manipulations', function (): void {
    makeCollate()->open('input.pdf')
        ->rotate(180)
        ->overlay('other.pdf')
        ->linearize()
        ->save('combined.pdf');

    $combined = storageContents('combined.pdf');
    $pageValues = qpdfPageValues(storageQpdfJson('combined.pdf'));
    $combinedXObject = qpdfValue($pageValues, '/Resources', '/XObject');

    expect(mb_substr($combined, 0, 4))->toBe('%PDF')
        ->and($pageValues['/Rotate'] ?? null)->toBe(180)
        ->and($combinedXObject)->toBeArray()
        ->and($combined)->toContain('/Linearized');
    assert(is_array($combinedXObject));
    expect(count($combinedXObject))->toBeGreaterThan(0);
});
