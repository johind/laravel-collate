<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('twelve-page.pdf')));
});

describe('onlyPages()', function () {
    it('converts an integer array to a comma-separated string', function () {
        $pending = makeCollate()->open('doc.pdf')->onlyPages([1, 2, 3]);

        expect(getProperty($pending, 'pageSelection'))->toBe('1,2,3');
    });

    it('passes a range string through unchanged', function () {
        $pending = makeCollate()->open('doc.pdf')->onlyPages('1-5,8,11-z');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-5,8,11-z');
    });

    it('throws when called after removePages()', function () {
        expect(fn () => makeCollate()->open('doc.pdf')
            ->removePages([1])
            ->onlyPages([2])
        )->toThrow(BadMethodCallException::class);
    });

    it('throws when called twice', function () {
        expect(fn () => makeCollate()->open('doc.pdf')
            ->onlyPages([1])
            ->onlyPages([2])
        )->toThrow(BadMethodCallException::class);
    });

    it('throws if called on null source', function () {
        expect(fn () => makeCollate()->merge('doc.pdf')->onlyPages([1]))
            ->toThrow(BadMethodCallException::class, 'Collate: cannot call onlyPages() when no source file is set.');
    });
});

describe('removePages()', function () {
    it('throws if called on null source', function () {
        expect(fn () => makeCollate()->merge('doc.pdf')->removePages([1]))
            ->toThrow(BadMethodCallException::class, 'Collate: cannot call removePages() when no source file is set.');
    });

    it('produces correct keep ranges from an integer array', function () {
        $pending = makeCollate()->open('doc.pdf')->removePages([3]);

        expect(getProperty($pending, 'pageSelection'))->toBe('1-2,4-z');
    });

    it('correctly removes the first page', function () {
        $pending = makeCollate()->open('doc.pdf')->removePages([1]);

        expect(getProperty($pending, 'pageSelection'))->toBe('2-z');
    });

    it('handles multiple non-consecutive pages', function () {
        $pending = makeCollate()->open('doc.pdf')->removePages([1, 3, 5]);

        expect(getProperty($pending, 'pageSelection'))->toBe('2,4,6-z');
    });

    it('expands a hyphenated range string into keep ranges', function () {
        $pending = makeCollate()->open('doc.pdf')->removePages('5-10');

        expect(getProperty($pending, 'pageSelection'))->toBe('1-4,11-z');
    });

    it('handles a mixed comma-and-hyphen string', function () {
        $pending = makeCollate()->open('doc.pdf')->removePages('1,3,5-8');

        expect(getProperty($pending, 'pageSelection'))->toBe('2,4,9-z');
    });

    it('trims whitespace from items in the input string', function () {
        $pending = makeCollate()->open('doc.pdf')->removePages('1, 3, 5-8');

        expect(getProperty($pending, 'pageSelection'))->toBe('2,4,9-z');
    });

    it('handles consecutive pages producing a clean trailing range', function () {
        // Removing 1-3 from a document should keep 4-z with no gaps
        $pending = makeCollate()->open('doc.pdf')->removePages('1-3');

        expect(getProperty($pending, 'pageSelection'))->toBe('4-z');
    });

    it('throws when called after onlyPages()', function () {
        expect(fn () => makeCollate()->open('doc.pdf')
            ->onlyPages([1])
            ->removePages([2])
        )->toThrow(BadMethodCallException::class);
    });

    it('throws when called twice', function () {
        expect(fn () => makeCollate()->open('doc.pdf')
            ->removePages([1])
            ->removePages([2])
        )->toThrow(BadMethodCallException::class);
    });

    it('throws for page number zero', function () {
        expect(fn () => makeCollate()->open('doc.pdf')->removePages([0]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws for negative page numbers', function () {
        expect(fn () => makeCollate()->open('doc.pdf')->removePages([-1]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws for non-numeric string values', function () {
        expect(fn () => makeCollate()->open('doc.pdf')->removePages(['foo']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('handles duplicate page numbers without producing duplicate keep ranges', function () {
        $pending = makeCollate()->open('doc.pdf')->removePages([3, 3]);

        expect(getProperty($pending, 'pageSelection'))->toBe('1-2,4-z');
    });
});

it('removePage() delegates correctly to removePages()', function () {
    $pending = makeCollate()->open('doc.pdf')->removePage(3);

    expect(getProperty($pending, 'pageSelection'))->toBe('1-2,4-z');
});

describe('addPage()', function () {
    it('delegates correctly to addPages() with explicit page number', function () {
        $pending = makeCollate()->open('doc.pdf')->addPage('doc.pdf', 5);
        $additions = getProperty($pending, 'additions');

        expect($additions)->toHaveCount(1)
            ->and($additions[0]['pages'])->toBe('5');
    });
});

describe('addPages()', function () {
    it('throws when an array of files is combined with a range', function () {
        expect(fn () => makeCollate()->open('doc.pdf')
            ->addPages(['doc.pdf', 'doc.pdf'], range: '1-3')
        )->toThrow(InvalidArgumentException::class);
    });
});
