<?php

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Tests\TestCase;

require_once __DIR__.'/TestHelpers.php';

uses(TestCase::class)->in(__DIR__);

afterEach(function () {
    Storage::disk()->deleteDirectory('test-fixtures');
    Storage::disk()->deleteDirectory('split');
    Storage::disk()->delete([
        'merged.pdf',
        'merged-with-add.pdf',
        'merged-with-page-selection.pdf',
        'merged-with-range.pdf',
        'merged-multiple.pdf',
        'extracted.pdf',
        'extracted-range.pdf',
        'removed-one.pdf',
        'removed-multi.pdf',
        'removed-range.pdf',
        'encrypted.pdf',
        'encrypted-full.pdf',
        'encrypted-restricted.pdf',
        'with-overlay.pdf',
        'with-underlay.pdf',
        'with-both.pdf',
        'rotated-90.pdf',
        'rotated-180.pdf',
        'rotated-specific.pdf',
        'rotated-multi.pdf',
        'linearized.pdf',
        'flattened.pdf',
        'with-metadata.pdf',
        'with-partial-metadata.pdf',
        'split-all.pdf',
    ]);
});
