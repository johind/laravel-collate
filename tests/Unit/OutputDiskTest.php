<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('s3');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

it('sets the output disk', function (): void {
    $pending = makeCollate()->open('doc.pdf')->toDisk('s3');

    expect(getProperty($pending, 'outputDisk'))->toBe('s3');
});

it('save() respects the disk set by toDisk()', function (): void {
    $pending = makeCollate()->open('doc.pdf')->toDisk('s3');

    $pending->save('output.pdf');

    expect(Storage::disk('s3')->exists('output.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->exists('output.pdf'))->toBeFalse();
});
