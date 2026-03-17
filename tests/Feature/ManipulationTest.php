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
});

it('rotates pages correctly', function (): void {
    $original = Storage::get('input.pdf');

    makeCollate()->open('input.pdf')
        ->rotate(90)
        ->save('rotated.pdf');

    $rotated = Storage::get('rotated.pdf');

    expect(mb_substr($rotated, 0, 4))->toBe('%PDF')
        ->and($rotated)->not->toBe($original);
});

it('overlays a PDF correctly', function (): void {
    $original = Storage::get('input.pdf');

    makeCollate()->open('input.pdf')
        ->overlay('other.pdf')
        ->save('overlaid.pdf');

    $overlaid = Storage::get('overlaid.pdf');

    expect(mb_substr($overlaid, 0, 4))->toBe('%PDF')
        ->and($overlaid)->not->toBe($original);
});

it('underlays a PDF correctly', function (): void {
    $original = Storage::get('input.pdf');

    makeCollate()->open('input.pdf')
        ->underlay('other.pdf')
        ->save('underlaid.pdf');

    $underlaid = Storage::get('underlaid.pdf');

    expect(mb_substr($underlaid, 0, 4))->toBe('%PDF')
        ->and($underlaid)->not->toBe($original);
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
    $original = Storage::get('input.pdf');

    makeCollate()->open('input.pdf')
        ->flatten()
        ->save('flattened.pdf');

    $flattened = Storage::get('flattened.pdf');

    expect(mb_substr($flattened, 0, 4))->toBe('%PDF')
        ->and(mb_strlen($flattened))->toBeGreaterThan(0);
});

it('combines multiple manipulations', function (): void {
    $original = Storage::get('input.pdf');

    makeCollate()->open('input.pdf')
        ->rotate(180)
        ->overlay('other.pdf')
        ->linearize()
        ->save('combined.pdf');

    $combined = Storage::get('combined.pdf');

    expect(mb_substr($combined, 0, 4))->toBe('%PDF')
        ->and($combined)->not->toBe($original)
        ->and($combined)->toContain('/Linearized');
});
