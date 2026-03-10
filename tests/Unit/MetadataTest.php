<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

describe('metadata()', function () {
    it('throws when no source file is set', function () {
        expect(fn () => makeCollate()->merge('doc.pdf')->metadata())
            ->toThrow(BadMethodCallException::class);
    });

    it('error message mentions both open() and inspect()', function () {
        expect(fn () => makeCollate()->merge('doc.pdf')->metadata())
            ->toThrow(BadMethodCallException::class, 'inspect()');
    });
});

describe('pageCount()', function () {
    it('throws when no source file is set', function () {
        expect(fn () => makeCollate()->merge('doc.pdf')->pageCount())
            ->toThrow(BadMethodCallException::class);
    });

    it('error message mentions both open() and inspect()', function () {
        expect(fn () => makeCollate()->merge('doc.pdf')->pageCount())
            ->toThrow(BadMethodCallException::class, 'inspect()');
    });
});

describe('withMetadata()', function () {
    it('maps title to the Title qpdf key', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata(title: 'My Doc');

        expect(getProperty($pending, 'metadata'))->toHaveKey('Title', 'My Doc');
    });

    it('maps author to the Author qpdf key', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata(author: 'Taylor');

        expect(getProperty($pending, 'metadata'))->toHaveKey('Author', 'Taylor');
    });

    it('maps subject to the Subject qpdf key', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata(subject: 'Reports');

        expect(getProperty($pending, 'metadata'))->toHaveKey('Subject', 'Reports');
    });

    it('maps keywords to the Keywords qpdf key', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata(keywords: 'pdf, test');

        expect(getProperty($pending, 'metadata'))->toHaveKey('Keywords', 'pdf, test');
    });

    it('only sets the fields that were passed', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata(title: 'Only Title');

        expect(getProperty($pending, 'metadata'))->toHaveCount(1)
            ->and(getProperty($pending, 'metadata'))->toHaveKey('Title');
    });

    it('setting no arguments leaves metadata empty', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata();

        expect(getProperty($pending, 'metadata'))->toBeEmpty();
    });

    it('is chainable', function () {
        $pending = makeCollate()->open('doc.pdf');

        expect($pending->withMetadata(title: 'Test'))->toBe($pending);
    });
});
