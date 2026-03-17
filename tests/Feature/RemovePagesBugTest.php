<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Facades\Collate;

beforeEach(function (): void {
    Storage::fake('local');
});

it('successfully removes the last page of a document', function (): void {
    $path = fixturePath('single-page.pdf'); // 1 page
    Storage::put('single.pdf', file_get_contents($path));

    $pending = Collate::open('single.pdf')->removePage(1);
    expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1');
    expect($pending->pageCount())->toBe(0);

    $content = $pending->content();
    expect($content)->toBeString();

    // qpdf produces a valid PDF with zero pages when all pages are excluded.
    // The important part is that it does NOT throw a ProcessFailedException.
    Storage::put('empty.pdf', $content);
    expect(Collate::open('empty.pdf')->pageCount())->toBe(0);
});

it('successfully removes the last page of a multi-page document', function (): void {
    $path = fixturePath('multi-page.pdf');
    Storage::put('multi.pdf', file_get_contents($path));

    $count = Collate::open('multi.pdf')->pageCount();

    $pending = Collate::open('multi.pdf')->removePage($count);
    expect($pending->pageCount())->toBe($count - 1);

    $content = $pending->content();
    expect($content)->toBeString();
});
