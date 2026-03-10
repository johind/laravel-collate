<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

it('can linearize a pdf', function () {
    $output = 'linearized.pdf';

    $result = Collate::open(pdf_fixture('multi-page.pdf'))
        ->linearize()
        ->save($output);

    expect($result)->toBeTrue();
    expect(pdf_page_count($output))->toBe(5);

    Storage::disk()->delete($output);
});
