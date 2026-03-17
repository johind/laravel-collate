<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('twelve-page.pdf')));
});

describe('onlyPages()', function (): void {
    it('converts an integer array to a comma-separated string', function (): void {
        $pending = makeCollate()->open('doc.pdf')->onlyPages([1, 2, 3]);

        expect(getProperty($pending, 'pageSelection'))->toBe('1,2,3');
    });

    it('passes a range string through unchanged', function (): void {
        $pending = makeCollate()->open('doc.pdf')->onlyPages('1-5,8,11-z');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-5,8,11-z');
    });

    it('throws when called after removePages()', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')
            ->removePages([1])
            ->onlyPages([2])
        )->toThrow(BadMethodCallException::class);
    });

    it('throws when called twice', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')
            ->onlyPages([1])
            ->onlyPages([2])
        )->toThrow(BadMethodCallException::class);
    });

    it('throws if called on null source', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->merge('doc.pdf')->onlyPages([1]))
            ->toThrow(BadMethodCallException::class, 'Collate: cannot call onlyPages() when no source file is set.');
    });
});

describe('removePages()', function (): void {
    it('throws if called on null source', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->merge('doc.pdf')->removePages([1]))
            ->toThrow(BadMethodCallException::class, 'Collate: cannot call removePages() when no source file is set.');
    });

    it('stores a single exclusion for an integer array', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages([3]);

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x3');
    });

    it('stores an exclusion for the first page', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages([1]);

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1');
    });

    it('stores multiple exclusions for non-consecutive pages', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages([1, 3, 5]);

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1,x3,x5');
    });

    it('stores a range exclusion for a hyphenated string', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('5-10');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x5-10');
    });

    it('stores mixed exclusions for a comma-and-hyphen string', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('1,3,5-8');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1,x3,x5-8');
    });

    it('trims whitespace from items in the input string', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('1, 3, 5-8');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1,x3,x5-8');
    });

    it('stores a range exclusion for consecutive pages', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('1-3');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1-3');
    });

    it('throws when called after onlyPages()', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')
            ->onlyPages([1])
            ->removePages([2])
        )->toThrow(BadMethodCallException::class);
    });

    it('throws when called twice', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')
            ->removePages([1])
            ->removePages([2])
        )->toThrow(BadMethodCallException::class);
    });

    it('throws for page number zero', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->removePages([0]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws for negative page numbers', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->removePages([-1]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws for non-numeric string values', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->removePages(['foo']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('handles duplicate page numbers in the exclusion', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages([3, 3]);

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x3,x3');
    });

    it('handles "z" as the last page', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('z');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,xz');
    });

    it('handles "z" in ranges (e.g., "10-z")', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('10-z');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x10-z');
    });

    it('handles "z" in an array', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages(['1', 'z']);

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1,xz');
    });

    it('stores an :odd exclusion for a full range', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('1-z:odd');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1-z:odd');
    });

    it('stores an :even exclusion for a full range', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('1-z:even');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1-z:even');
    });

    it('stores an :odd exclusion for a partial range', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('3-8:odd');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x3-8:odd');
    });

    it('stores an :even exclusion for a partial range', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('3-8:even');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x3-8:even');
    });

    it('stores an :odd exclusion on a single page', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('3:odd');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x3:odd');
    });

    it('stores an :even exclusion on a single page', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('3:even');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x3:even');
    });

    it('combines plain and :odd exclusions', function (): void {
        $pending = makeCollate()->open('doc.pdf')->removePages('1,4-8:odd');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x1,x4-8:odd');
    });
});

it('removePage() delegates correctly to removePages()', function (): void {
    $pending = makeCollate()->open('doc.pdf')->removePage(3);

    expect(getProperty($pending, 'pageSelection'))->toBe('1-z,x3');
});

describe('addPage()', function (): void {
    it('delegates correctly to addPages() with explicit page number', function (): void {
        $pending = makeCollate()->open('doc.pdf')->addPage('doc.pdf', 5);
        $additions = getProperty($pending, 'additions');

        expect($additions)->toHaveCount(1)
            ->and($additions[0]['pages'])->toBe('5');
    });
});

describe('addPages()', function (): void {
    it('supports "z" in the range parameter', function (): void {
        $pending = makeCollate()->open('doc.pdf')->addPages('doc.pdf', '1-z');

        expect(getProperty($pending, 'additions')[0]['pages'])->toBe('1-z');
    });

    it('throws when an array of files is combined with a range', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')
            ->addPages(['doc.pdf', 'doc.pdf'], range: '1-3')
        )->toThrow(InvalidArgumentException::class);
    });
});
