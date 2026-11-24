<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::put('doc.pdf', fixtureContents('single-page.pdf'));
});

it('throws for invalid rotation degrees', function (): void {
    expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->rotate(45))
        ->toThrow(InvalidArgumentException::class);
});

it('throws for an invalid page range', function (string $range): void {
    expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->rotate(90, $range))
        ->toThrow(InvalidArgumentException::class);
})->with(['banana', '0', '1-', '1,,2', 'r0', '1-Z', '1-z:ODD', '1:odd,2']);

it('accepts the page range syntax qpdf supports for rotation', function (string $range): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(90, $range);

    expect(qpdfValue(getArrayProperty($pending, 'rotations'), 0, 'pages'))->toBe($range);
})->with(['1-z,x2', 'r1', 'r3-r1', '1-z:even']);

it('trims whitespace around the page range', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(90, ' 1-z ');

    expect(qpdfValue(getArrayProperty($pending, 'rotations'), 0, 'pages'))->toBe('1-z');
});

it('throws for negative degrees', function (): void {
    expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->rotate(-90))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts 0 degrees', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(0);

    expect(getProperty($pending, 'rotations'))->toHaveCount(1);
});

it('accepts 90 degrees', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(90);

    expect(qpdfValue(getArrayProperty($pending, 'rotations'), 0, 'degrees'))->toBe(90);
});

it('accepts 180 degrees', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(180);

    expect(qpdfValue(getArrayProperty($pending, 'rotations'), 0, 'degrees'))->toBe(180);
});

it('accepts 270 degrees', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(270);

    expect(qpdfValue(getArrayProperty($pending, 'rotations'), 0, 'degrees'))->toBe(270);
});

it('defaults to all pages when no range is given', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(90);

    expect(qpdfValue(getArrayProperty($pending, 'rotations'), 0, 'pages'))->toBe('1-z');
});

it('stores an explicit page range', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(90, range: '2-4');

    expect(qpdfValue(getArrayProperty($pending, 'rotations'), 0, 'pages'))->toBe('2-4');
});

it('accumulates multiple rotate() calls independently', function (): void {
    $pending = makeCollate()->open('doc.pdf')
        ->rotate(90, range: '1-3')
        ->rotate(180, range: '5');

    $rotations = getArrayProperty($pending, 'rotations');

    expect($rotations)->toHaveCount(2)
        ->and($rotations[0])->toBe(['degrees' => 90, 'pages' => '1-3'])
        ->and($rotations[1])->toBe(['degrees' => 180, 'pages' => '5']);
});
