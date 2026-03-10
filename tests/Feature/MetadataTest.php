<?php

use Johind\Collate\Facades\Collate;

it('can read metadata from a pdf', function () {
    $meta = Collate::open(pdf_fixture('single-page.pdf'))->metadata();

    expect($meta)->toBeInstanceOf(\Johind\Collate\PdfMetadata::class);
});
