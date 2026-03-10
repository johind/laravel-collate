<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

it('can split a pdf into multiple files', function () {
    $paths = Collate::open(pdf_fixture('multi-page.pdf'))
        ->split('split/page-{page}.pdf');

    expect($paths)->toHaveCount(5)
        ->and($paths[0])->toBe('split/page-1.pdf')
        ->and($paths[1])->toBe('split/page-2.pdf')
        ->and(pdf_page_count($paths[0]))->toBe(1)
        ->and(pdf_page_count($paths[1]))->toBe(1);

    Storage::disk()->delete($paths->all());
});

it('can split with path without placeholder', function () {
    $paths = Collate::open(pdf_fixture('multi-page.pdf'))
        ->split('split-all.pdf');

    expect($paths)->toHaveCount(5);

    Storage::disk()->delete($paths->all());
});
