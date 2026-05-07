<?php

declare(strict_types=1);

use Johind\Collate\CollateFake;
use Johind\Collate\PendingCollateFake;
use PHPUnit\Framework\ExpectationFailedException;

it('inspect() returns a PendingCollateFake', function (): void {
    $fake = new CollateFake;

    expect($fake->inspect('doc.pdf'))->toBeInstanceOf(PendingCollateFake::class);
});

it('inspect() records the operation', function (): void {
    $fake = new CollateFake;
    $fake->inspect('doc.pdf');

    expect($fake->recorded())->toHaveCount(1);
});

it('inspect() sets the source path', function (): void {
    $fake = new CollateFake;
    $pending = $fake->inspect('doc.pdf');

    expect($pending->sourcePath())->toBe('doc.pdf');
});

it('inspect() followed by pageCount() does not hit real processes', function (): void {
    $fake = new CollateFake;
    $count = $fake->inspect('doc.pdf')->pageCount();

    expect($count)->toBe(3);
});

it('sums page counts from source and additions', function (): void {
    $fake = new CollateFake;
    $pending = $fake->open('a.pdf')->addPages('b.pdf');

    // source (3 pages) + addition (3 pages) = 6
    expect($pending->pageCount())->toBe(6);
});

it('split() returns correctly sized collection based on total pages', function (): void {
    $fake = new CollateFake;
    $pending = $fake->open('a.pdf')->addPages('b.pdf');

    $paths = $pending->split('page-{page}.pdf');

    expect($paths)->toHaveCount(6)
        ->and($paths[0])->toBe('page-1.pdf')
        ->and($paths[5])->toBe('page-6.pdf');
});

it('exposes fake inspection helpers for new document state', function (): void {
    $fake = new CollateFake;
    $pending = $fake->open('doc.pdf')
        ->encrypt('secret')
        ->linearize()
        ->flatten()
        ->optimize()
        ->withoutMetadata();

    $size = $pending->pageSize();

    expect($pending->hasPassword())->toBeTrue()
        ->and($pending->isLinearized())->toBeTrue()
        ->and($pending->isFlattened())->toBeTrue()
        ->and($pending->isOptimized())->toBeTrue()
        ->and($pending->hasStrippedMetadata())->toBeTrue()
        ->and($pending->pdfVersion())->toBe('1.7')
        ->and($size->width)->toBe(612.0)
        ->and($size->height)->toBe(792.0);
});

describe('toDisk()', function (): void {
    it('exposes the output disk via outputDisk()', function (): void {
        $fake = new CollateFake;
        $pending = $fake->open('doc.pdf')->toDisk('s3');

        expect($pending->outputDisk())->toBe('s3');
    });

    it('returns null when no output disk is set', function (): void {
        $fake = new CollateFake;
        $pending = $fake->open('doc.pdf');

        expect($pending->outputDisk())->toBeNull();
    });
});

describe('assertions', function (): void {
    it('can assert that a PDF was saved', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->save('output.pdf');

        $fake->assertSaved('output.pdf');
        $fake->assertSaved(null, fn ($p): bool => $p->sourcePath() === 'doc.pdf');
    });

    it('fails when asserting a missing saved PDF', function (): void {
        $fake = new CollateFake;

        expect(fn () => $fake->assertSaved())
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be saved, but none was.');
    });

    it('fails when asserting the wrong saved path', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->save('output.pdf');

        expect(fn () => $fake->assertSaved('wrong.pdf'))
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be saved to [wrong.pdf], but it was not.');
    });

    it('fails when no saved PDF matches the callback', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->save('output.pdf');

        expect(fn () => $fake->assertSaved(null, fn (): bool => false))
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be saved matching the given callback, but none matched.');
    });

    it('can assert that a PDF was downloaded', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->download('download.pdf');

        $fake->assertDownloaded('download.pdf');
        $fake->assertDownloaded(null, fn ($p): bool => $p->sourcePath() === 'doc.pdf');
    });

    it('fails when asserting a missing download', function (): void {
        $fake = new CollateFake;

        expect(fn () => $fake->assertDownloaded())
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be downloaded, but none was.');
    });

    it('fails when asserting the wrong downloaded filename', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->download('download.pdf');

        expect(fn () => $fake->assertDownloaded('wrong.pdf'))
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be downloaded as [wrong.pdf], but it was not.');
    });

    it('fails when no downloaded PDF matches the callback', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->download('download.pdf');

        expect(fn () => $fake->assertDownloaded(null, fn (): bool => false))
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be downloaded matching the given callback, but none matched.');
    });

    it('can assert that a PDF was streamed', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->stream('stream.pdf');

        $fake->assertStreamed('stream.pdf');
        $fake->assertStreamed(null, fn ($p): bool => $p->sourcePath() === 'doc.pdf');
    });

    it('fails when asserting a missing stream', function (): void {
        $fake = new CollateFake;

        expect(fn () => $fake->assertStreamed())
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be streamed, but none was.');
    });

    it('fails when asserting the wrong streamed filename', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->stream('stream.pdf');

        expect(fn () => $fake->assertStreamed('wrong.pdf'))
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be streamed as [wrong.pdf], but it was not.');
    });

    it('fails when no streamed PDF matches the callback', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->stream('stream.pdf');

        expect(fn () => $fake->assertStreamed(null, fn (): bool => false))
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be streamed matching the given callback, but none matched.');
    });

    it('can assert that nothing was saved, downloaded, or streamed', function (): void {
        $fake = new CollateFake;

        $fake->assertNothingSaved();
        $fake->assertNothingDownloaded();
        $fake->assertNothingStreamed();
    });

    it('fails when asserting nothing was saved after a save', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->save('output.pdf');

        expect(fn () => $fake->assertNothingSaved())
            ->toThrow(ExpectationFailedException::class, 'Expected no PDFs to be saved, but 1 were.');
    });

    it('fails when asserting nothing was downloaded after a download', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->download('download.pdf');

        expect(fn () => $fake->assertNothingDownloaded())
            ->toThrow(ExpectationFailedException::class, 'Expected no PDFs to be downloaded, but 1 were.');
    });

    it('fails when asserting nothing was streamed after a stream', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->stream('stream.pdf');

        expect(fn () => $fake->assertNothingStreamed())
            ->toThrow(ExpectationFailedException::class, 'Expected no PDFs to be streamed, but 1 were.');
    });

    it('can assert that a PDF was split', function (): void {
        $fake = new CollateFake;
        $fake->open('doc.pdf')->split('page-{page}.pdf');

        $fake->assertSplit();
    });

    it('fails when asserting a missing split', function (): void {
        $fake = new CollateFake;

        expect(fn () => $fake->assertSplit())
            ->toThrow(ExpectationFailedException::class, 'Expected a PDF to be split, but none was.');
    });
});
