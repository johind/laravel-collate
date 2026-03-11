<?php

use Johind\Collate\CollateFake;
use Johind\Collate\PendingCollateFake;

it('inspect() returns a PendingCollateFake', function () {
    $fake = new CollateFake;

    expect($fake->inspect('doc.pdf'))->toBeInstanceOf(PendingCollateFake::class);
});

it('inspect() records the operation', function () {
    $fake = new CollateFake;
    $fake->inspect('doc.pdf');

    expect($fake->recorded())->toHaveCount(1);
});

it('inspect() sets the source path', function () {
    $fake = new CollateFake;
    $pending = $fake->inspect('doc.pdf');

    expect($pending->sourcePath())->toBe('doc.pdf');
});

it('inspect() followed by pageCount() does not hit real processes', function () {
    $fake = new CollateFake;
    $count = $fake->inspect('doc.pdf')->pageCount();

    expect($count)->toBe(3);
});

it('sums page counts from source and additions', function () {
    $fake = new Johind\Collate\CollateFake;
    $pending = $fake->open('a.pdf')->addPage('b.pdf');

    // source (3 pages) + addition (3 pages) = 6
    expect($pending->pageCount())->toBe(6);
});

it('split() returns correctly sized collection based on total pages', function () {
    $fake = new Johind\Collate\CollateFake;
    $pending = $fake->open('a.pdf')->addPage('b.pdf');

    $paths = $pending->split('page-{page}.pdf');

    expect($paths)->toHaveCount(6)
        ->and($paths[0])->toBe('page-1.pdf')
        ->and($paths[5])->toBe('page-6.pdf');
});
