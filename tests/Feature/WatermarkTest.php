<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

it('can overlay a pdf on top', function () {
    $output = 'with-overlay.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->overlay(pdf_fixture('single-page.pdf'))
        ->save($output);

    expect($result)->toBeTrue();
    expect(Storage::disk()->exists($output))->toBeTrue();

    Storage::disk()->delete($output);
});

it('can underlay a pdf behind', function () {
    $output = 'with-underlay.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->underlay(pdf_fixture('single-page.pdf'))
        ->save($output);

    expect($result)->toBeTrue();
    expect(Storage::disk()->exists($output))->toBeTrue();

    Storage::disk()->delete($output);
});
