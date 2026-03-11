<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

describe('metadata()', function () {
    it('throws when no source file is set', function () {
        expect(fn () => (new Johind\Collate\PendingCollate(makeCollate()))->metadata())
            ->toThrow(BadMethodCallException::class);
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

    it('maps creator to the Creator qpdf key', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata(creator: 'My App');

        expect(getProperty($pending, 'metadata'))->toHaveKey('Creator', 'My App');
    });

    it('maps producer to the Producer qpdf key', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata(producer: 'Collate');

        expect(getProperty($pending, 'metadata'))->toHaveKey('Producer', 'Collate');
    });

    it('maps creationDate to the CreationDate qpdf key', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata(creationDate: '2025-01-01');

        expect(getProperty($pending, 'metadata'))->toHaveKey('CreationDate', '2025-01-01');
    });

    it('maps modDate to the ModDate qpdf key', function () {
        $pending = makeCollate()->open('doc.pdf')->withMetadata(modDate: '2025-06-15');

        expect(getProperty($pending, 'metadata'))->toHaveKey('ModDate', '2025-06-15');
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
