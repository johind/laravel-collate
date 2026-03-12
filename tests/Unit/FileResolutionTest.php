<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

it('resolves a local disk path to an absolute filesystem path', function (): void {
    $pending = makeCollate()->open('doc.pdf');
    $source = getProperty($pending, 'source');

    expect($source)->toBeString()
        ->and(str_ends_with((string) $source, 'doc.pdf'))->toBeTrue();
});

it('resolves an UploadedFile to its real path', function (): void {
    $uploaded = UploadedFile::fake()->createWithContent(
        'upload.pdf',
        file_get_contents(fixturePath('single-page.pdf'))
    );

    $pending = makeCollate()->open($uploaded);
    $source = getProperty($pending, 'source');

    expect($source)->toBe($uploaded->getRealPath());
});

it('throws FileNotFoundException for an UploadedFile that is no longer on disk', function (): void {
    $mockFile = Mockery::mock(UploadedFile::class);
    $mockFile->shouldReceive('getRealPath')->andReturn(false);

    expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open($mockFile))
        ->toThrow(FileNotFoundException::class);
});

it('local disk files are not added to tempInputFiles', function (): void {
    $pending = makeCollate()->open('doc.pdf');

    expect(getProperty($pending, 'tempInputFiles'))->toBeEmpty();
});

it('resolveFilePath can be called directly for an addition file', function (): void {
    Storage::put('addition.pdf', file_get_contents(fixturePath('single-page.pdf')));

    $pending = makeCollate()->open('doc.pdf')->addPages('addition.pdf');

    $additions = getProperty($pending, 'additions');
    $additionPath = $additions[0]['file'];

    expect(str_ends_with((string) $additionPath, 'addition.pdf'))->toBeTrue();
});

it('addition files on local disks are not added to tempInputFiles', function (): void {
    Storage::put('addition.pdf', file_get_contents(fixturePath('single-page.pdf')));

    $pending = makeCollate()->open('doc.pdf')->addPages('addition.pdf');

    expect(getProperty($pending, 'tempInputFiles'))->toBeEmpty();
});
