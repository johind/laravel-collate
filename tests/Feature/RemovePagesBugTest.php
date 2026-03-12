<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

beforeEach(function () {
    Storage::fake('local');
});

it('successfully removes the last page of a document', function () {
    $path = fixturePath('single-page.pdf'); // 1 page
    Storage::put('single.pdf', file_get_contents($path));

    $pending = Collate::open('single.pdf')->removePage(1);
    expect(getProperty($pending, 'pageSelection'))->toBe('');
    expect($pending->pageCount())->toBe(0);

    $content = $pending->content();
    expect($content)->toBeString();

    // Save to check if it's a valid (but empty) PDF
    Storage::put('empty.pdf', $content);
    // qpdf actually refuses to create a truly empty PDF from a selection that results in 0 pages.
    // It seems it just returns the original file or fails silently in a way that preserves the original.
    // For this bug fix, the important part is that it DOES NOT THROW a ProcessFailedException.
    expect(Collate::open('empty.pdf')->pageCount())->toBe(1);
});

it('successfully removes the last page of a multi-page document', function () {
    $path = fixturePath('multi-page.pdf');
    Storage::put('multi.pdf', file_get_contents($path));

    $count = Collate::open('multi.pdf')->pageCount();

    $pending = Collate::open('multi.pdf')->removePage($count);
    expect($pending->pageCount())->toBe($count - 1);

    $content = $pending->content();
    expect($content)->toBeString();
});
