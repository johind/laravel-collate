<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

it('can merge multiple pdfs', function () {
    $output = 'merged.pdf';

    $result = Collate::merge(
        pdf_fixture('single-page.pdf'),
        pdf_fixture('multi-page.pdf'),
    )->save($output);

    expect($result)->toBeTrue()
        ->and(pdf_page_count($output))->toBe(6);

    Storage::disk()->delete($output);
});

it('can merge using open and addPage', function () {
    $output = 'merged-with-add.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->addPage(pdf_fixture('multi-page.pdf'))
        ->save($output);

    expect($result)->toBeTrue()
        ->and(pdf_page_count($output))->toBe(6);

    Storage::disk()->delete($output);
});

it('can add specific pages from a file', function () {
    $output = 'merged-with-page-selection.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->addPage(pdf_fixture('multi-page.pdf'), pageNumber: 1)
        ->save($output);

    expect($result)->toBeTrue()
        ->and(pdf_page_count($output))->toBe(2);

    Storage::disk()->delete($output);
});

it('can add a range of pages', function () {
    $output = 'merged-with-range.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->addPages(pdf_fixture('multi-page.pdf'), range: '1-2')
        ->save($output);

    expect($result)->toBeTrue()
        ->and(pdf_page_count($output))->toBe(3);

    Storage::disk()->delete($output);
});

it('can add multiple files at once', function () {
    $output = 'merged-multiple.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->addPages([
            pdf_fixture('single-page.pdf'),
            pdf_fixture('single-page.pdf'),
        ])
        ->save($output);

    expect($result)->toBeTrue()
        ->and(pdf_page_count($output))->toBe(3);

    Storage::disk()->delete($output);
});
