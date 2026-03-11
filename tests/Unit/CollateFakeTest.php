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
