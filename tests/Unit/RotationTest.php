<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

it('throws for invalid rotation degrees', function (): void {
    expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->rotate(45))
        ->toThrow(InvalidArgumentException::class);
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

    expect(getProperty($pending, 'rotations')[0]['degrees'])->toBe(90);
});

it('accepts 180 degrees', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(180);

    expect(getProperty($pending, 'rotations')[0]['degrees'])->toBe(180);
});

it('accepts 270 degrees', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(270);

    expect(getProperty($pending, 'rotations')[0]['degrees'])->toBe(270);
});

it('defaults to all pages when no range is given', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(90);

    expect(getProperty($pending, 'rotations')[0]['pages'])->toBe('1-z');
});

it('stores an explicit page range', function (): void {
    $pending = makeCollate()->open('doc.pdf')->rotate(90, range: '2-4');

    expect(getProperty($pending, 'rotations')[0]['pages'])->toBe('2-4');
});

it('accumulates multiple rotate() calls independently', function (): void {
    $pending = makeCollate()->open('doc.pdf')
        ->rotate(90, range: '1-3')
        ->rotate(180, range: '5');

    $rotations = getProperty($pending, 'rotations');

    expect($rotations)->toHaveCount(2)
        ->and($rotations[0])->toBe(['degrees' => 90, 'pages' => '1-3'])
        ->and($rotations[1])->toBe(['degrees' => 180, 'pages' => '5']);
});
