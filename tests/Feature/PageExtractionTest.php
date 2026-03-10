<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

it('can extract specific pages using onlyPages', function () {
    $output = 'extracted.pdf';

    $result = Collate::open(pdf_fixture('multi-page.pdf'))
        ->onlyPages([1, 2])
        ->save($output);

    expect($result)->toBeTrue();
    expect(pdf_page_count($output))->toBe(2);

    Storage::disk()->delete($output);
});

it('can extract pages using range string', function () {
    $output = 'extracted-range.pdf';

    $result = Collate::open(pdf_fixture('multi-page.pdf'))
        ->onlyPages('1-2')
        ->save($output);

    expect($result)->toBeTrue();
    expect(pdf_page_count($output))->toBe(2);

    Storage::disk()->delete($output);
});
