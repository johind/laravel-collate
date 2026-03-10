<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

it('can encrypt with password', function () {
    $output = 'encrypted.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->encrypt('secret')
        ->save($output);

    expect($result)->toBeTrue();
    expect(Storage::disk()->exists($output))->toBeTrue();

    Storage::disk()->delete($output);
});

it('can encrypt with separate user and owner passwords', function () {
    $output = 'encrypted-full.pdf';

    $result = Collate::open(pdf_fixture('single-page.pdf'))
        ->encrypt(
            userPassword: 'readonly',
            ownerPassword: 'fullaccess',
            bitLength: 256,
        )
        ->save($output);

    expect($result)->toBeTrue();
    expect(Storage::disk()->exists($output))->toBeTrue();

    Storage::disk()->delete($output);
});
