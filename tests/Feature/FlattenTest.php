<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

it('can flatten a pdf', function () {
    $output = 'flattened.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->flatten()
        ->save($output);

    expect($result)->toBeTrue();
    expect(pdf_page_count($output))->toBe(1);

    Storage::disk()->delete($output);
});
