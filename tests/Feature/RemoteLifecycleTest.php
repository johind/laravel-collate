<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

beforeEach(function (): void {
    if (! qpdfAvailable()) {
        test()->skip('qpdf binary not available');
    }

    Storage::fake('s3');
    // Upload a real PDF to the fake S3 disk
    Storage::disk('s3')->put('remote.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

it('does not delete remote temp files until the instance is destroyed', function (): void {
    // We need to use the real Collate class but with the fake disk
    $pdf = Collate::fromDisk('s3')->open('remote.pdf');

    $tempFile = getProperty($pdf, 'source');
    expect(file_exists($tempFile))->toBeTrue();

    // First call to a processing method
    $pdf->content();

    // The file should STILL exist
    expect(file_exists($tempFile))->toBeTrue('Remote temp file was deleted too early!');
});
