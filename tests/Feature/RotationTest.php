<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

it('can rotate pages by 90 degrees', function () {
    $output = 'rotated-90.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->rotate(90)
        ->save($output);

    expect($result)->toBeTrue();
    expect(pdf_page_count($output))->toBe(1);

    Storage::disk()->delete($output);
});

it('can rotate pages by 180 degrees', function () {
    $output = 'rotated-180.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->rotate(180)
        ->save($output);

    expect($result)->toBeTrue();
    expect(pdf_page_count($output))->toBe(1);

    Storage::disk()->delete($output);
});

it('can rotate specific pages only', function () {
    $output = 'rotated-specific.pdf';

    $result = Collate::open(pdf_fixture('multi-page.pdf'))
        ->rotate(90, pages: '1')
        ->save($output);

    expect($result)->toBeTrue();
    expect(pdf_page_count($output))->toBe(5);

    Storage::disk()->delete($output);
});

it('can apply multiple rotations', function () {
    $output = 'rotated-multi.pdf';

    $result = Collate::open(pdf_fixture('multi-page.pdf'))
        ->rotate(90, pages: '1')
        ->rotate(180, pages: '2')
        ->save($output);

    expect($result)->toBeTrue();
    expect(pdf_page_count($output))->toBe(5);

    Storage::disk()->delete($output);
});
