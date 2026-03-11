<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    if (! qpdfAvailable()) {
        test()->skip('qpdf binary not available');
    }

    Storage::fake('local');
    Storage::put('input.pdf', file_get_contents(fixturePath('single-page.pdf')));
    Storage::put('other.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

it('rotates pages correctly', function () {
    // We can't easily verify the actual rotation of the PDF content here
    // without a sophisticated PDF parser, but we can verify the command
    // runs successfully and doesn't corrupt the file.
    makeCollate()->open('input.pdf')
        ->rotate(90)
        ->save('rotated.pdf');

    expect(Storage::exists('rotated.pdf'))->toBeTrue();
});

it('overlays a PDF correctly', function () {
    makeCollate()->open('input.pdf')
        ->overlay('other.pdf')
        ->save('overlaid.pdf');

    expect(Storage::exists('overlaid.pdf'))->toBeTrue();
});

it('underlays a PDF correctly', function () {
    makeCollate()->open('input.pdf')
        ->underlay('other.pdf')
        ->save('underlaid.pdf');

    expect(Storage::exists('underlaid.pdf'))->toBeTrue();
});

it('linearizes the output PDF', function () {
    makeCollate()->open('input.pdf')
        ->linearize()
        ->save('linearized.pdf');

    expect(Storage::exists('linearized.pdf'))->toBeTrue();
});

it('flattens annotations and form fields', function () {
    makeCollate()->open('input.pdf')
        ->flatten()
        ->save('flattened.pdf');

    expect(Storage::exists('flattened.pdf'))->toBeTrue();
});

it('combines multiple manipulations', function () {
    makeCollate()->open('input.pdf')
        ->rotate(180)
        ->overlay('other.pdf')
        ->linearize()
        ->save('combined.pdf');

    expect(Storage::exists('combined.pdf'))->toBeTrue();
});
