<?php

declare(strict_types=1);

use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

describe('isEncrypted()', function (): void {
    it('throws when no source file is set', function (): void {
        expect(fn (): bool => (new Johind\Collate\PendingCollate(makeCollate()))->isEncrypted())
            ->toThrow(
                BadMethodCallException::class,
                'Collate: cannot call isEncrypted() when no source file is set. Use open() or inspect() first.',
            );
    });

    it('throws when qpdf returns an unexpected inspection exit code', function (): void {
        Process::fake(fn () => new FakeProcessResult(exitCode: 3, errorOutput: 'qpdf failed'));

        expect(fn (): bool => makeCollate()->inspect('doc.pdf')->isEncrypted())
            ->toThrow(Johind\Collate\Exceptions\ProcessFailedException::class, 'Collate: failed to inspect PDF');
    });
});

describe('hasPassword()', function (): void {
    it('throws when no source file is set', function (): void {
        expect(fn (): bool => (new Johind\Collate\PendingCollate(makeCollate()))->hasPassword())
            ->toThrow(BadMethodCallException::class);
    });
});

describe('metadata()', function (): void {
    it('reads metadata from cached qpdf JSON', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:5 0 R' => [
                        'value' => [
                            '/Title' => 'u:Cached Title',
                            '/Author' => 'u:Cached Author',
                        ],
                    ],
                    'trailer' => [
                        'value' => [
                            '/Info' => '5 0 R',
                        ],
                    ],
                ],
            ],
        ]);

        $meta = $pending->metadata();

        expect($meta->title)->toBe('Cached Title')
            ->and($meta->author)->toBe('Cached Author');
    });
});

describe('isLinearized()', function (): void {
    it('throws when no source file is set', function (): void {
        expect(fn (): bool => (new Johind\Collate\PendingCollate(makeCollate()))->isLinearized())
            ->toThrow(BadMethodCallException::class);
    });

    it('returns true when qpdf JSON contains a Linearized dictionary', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:1 0 R' => [
                        'value' => [
                            '/Linearized' => 1,
                            '/L' => 1000,
                        ],
                    ],
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        expect($pending->isLinearized())->toBeTrue();
    });

    it('returns false when qpdf JSON has no Linearized dictionary', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:1 0 R' => [
                        'value' => [
                            '/Type' => '/Catalog',
                        ],
                    ],
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        expect($pending->isLinearized())->toBeFalse();
    });
});

describe('pdfVersion()', function (): void {
    it('throws when no source file is set', function (): void {
        expect(fn (): string => (new Johind\Collate\PendingCollate(makeCollate()))->pdfVersion())
            ->toThrow(BadMethodCallException::class);
    });

    it('throws when qpdf JSON reading fails', function (): void {
        Process::fake(fn () => new FakeProcessResult(exitCode: 1, errorOutput: 'cannot read file'));

        expect(fn (): string => makeCollate()->inspect('doc.pdf')->pdfVersion())
            ->toThrow(Johind\Collate\Exceptions\ProcessFailedException::class, 'Collate: failed to read PDF');
    });

    it('throws when qpdf JSON has an unexpected structure', function (): void {
        Process::fake(fn () => new FakeProcessResult(output: json_encode(['qpdf' => []])));

        expect(fn (): string => makeCollate()->inspect('doc.pdf')->pdfVersion())
            ->toThrow(RuntimeException::class, 'Collate: failed to parse qpdf JSON output');
    });

    it('throws when qpdf JSON does not include a version string', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [],
            'qpdf' => [
                [],
                [
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        expect(fn (): string => $pending->pdfVersion())
            ->toThrow(RuntimeException::class);
    });

    it('memoizes qpdf JSON across inspection methods and requests JSON version 2', function (): void {
        Process::fake(fn () => new FakeProcessResult(output: json_encode([
            'pages' => [
                ['pageposfrom1' => 1, 'object' => '3 0 R'],
            ],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:3 0 R' => [
                        'value' => [
                            '/Type' => '/Page',
                            '/MediaBox' => [0, 0, 612, 792],
                        ],
                    ],
                    'obj:5 0 R' => [
                        'value' => [
                            '/Title' => 'u:Memoized Title',
                        ],
                    ],
                    'trailer' => [
                        'value' => [
                            '/Info' => '5 0 R',
                        ],
                    ],
                ],
            ],
        ])));

        $pending = makeCollate()->inspect('doc.pdf');

        expect($pending->pdfVersion())->toBe('1.7')
            ->and($pending->pageSize()->width)->toBe(612.0)
            ->and($pending->isLinearized())->toBeFalse()
            ->and($pending->metadata()->title)->toBe('Memoized Title');

        Process::assertRanTimes(
            fn ($process): bool => is_array($process->command)
                && in_array('--json=2', $process->command, true),
            1,
        );
    });
});

describe('pageSize()', function (): void {
    it('throws when no source file is set', function (): void {
        expect(fn (): Johind\Collate\PageSize => (new Johind\Collate\PendingCollate(makeCollate()))->pageSize())
            ->toThrow(BadMethodCallException::class);
    });

    it('reads an inherited MediaBox from the parent page tree node', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [
                ['pageposfrom1' => 1, 'object' => '3 0 R'],
            ],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:2 0 R' => [
                        'value' => [
                            '/Type' => '/Pages',
                            '/MediaBox' => [0, 0, 300, 400],
                        ],
                    ],
                    'obj:3 0 R' => [
                        'value' => [
                            '/Type' => '/Page',
                            '/Parent' => '2 0 R',
                        ],
                    ],
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        $size = $pending->pageSize();

        expect($size->width)->toBe(300.0)
            ->and($size->height)->toBe(400.0);
    });

    it('stops walking parent references when the page tree has a cycle', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [
                ['pageposfrom1' => 1, 'object' => '4 0 R'],
            ],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:2 0 R' => [
                        'value' => [
                            '/Type' => '/Pages',
                            '/Parent' => '3 0 R',
                        ],
                    ],
                    'obj:3 0 R' => [
                        'value' => [
                            '/Type' => '/Pages',
                            '/Parent' => '2 0 R',
                        ],
                    ],
                    'obj:4 0 R' => [
                        'value' => [
                            '/Type' => '/Page',
                            '/Parent' => '2 0 R',
                        ],
                    ],
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        expect(fn (): Johind\Collate\PageSize => $pending->pageSize())
            ->toThrow(RuntimeException::class, 'Collate: page 1 does not have a valid /MediaBox.');
    });

    it('throws when the page tree does not contain a valid MediaBox', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [
                ['pageposfrom1' => 1, 'object' => '3 0 R'],
            ],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:3 0 R' => [
                        'value' => [
                            '/Type' => '/Page',
                            '/MediaBox' => [0, 0, 612],
                        ],
                    ],
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        expect(fn (): Johind\Collate\PageSize => $pending->pageSize())
            ->toThrow(RuntimeException::class, 'Collate: page 1 does not have a valid /MediaBox.');
    });

    it('walks through multiple parent page tree nodes for MediaBox', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [
                ['pageposfrom1' => 1, 'object' => '4 0 R'],
            ],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:2 0 R' => [
                        'value' => [
                            '/Type' => '/Pages',
                            '/MediaBox' => [10, 20, 210, 320],
                        ],
                    ],
                    'obj:3 0 R' => [
                        'value' => [
                            '/Type' => '/Pages',
                            '/Parent' => '2 0 R',
                        ],
                    ],
                    'obj:4 0 R' => [
                        'value' => [
                            '/Type' => '/Page',
                            '/Parent' => '3 0 R',
                        ],
                    ],
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        $size = $pending->pageSize();

        expect($size->width)->toBe(200.0)
            ->and($size->height)->toBe(300.0);
    });

    it('prefers the page MediaBox over an inherited MediaBox', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [
                ['pageposfrom1' => 1, 'object' => '3 0 R'],
            ],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:2 0 R' => [
                        'value' => [
                            '/Type' => '/Pages',
                            '/MediaBox' => [0, 0, 300, 400],
                        ],
                    ],
                    'obj:3 0 R' => [
                        'value' => [
                            '/Type' => '/Page',
                            '/Parent' => '2 0 R',
                            '/MediaBox' => [0, 0, 612, 792],
                        ],
                    ],
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        $size = $pending->pageSize();

        expect($size->width)->toBe(612.0)
            ->and($size->height)->toBe(792.0);
    });

    it('returns the unrotated MediaBox size even when Rotate is set', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [
                ['pageposfrom1' => 1, 'object' => '3 0 R'],
            ],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:3 0 R' => [
                        'value' => [
                            '/Type' => '/Page',
                            '/MediaBox' => [0, 0, 612, 792],
                            '/Rotate' => 90,
                        ],
                    ],
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        $size = $pending->pageSize();

        expect($size->width)->toBe(612.0)
            ->and($size->height)->toBe(792.0);
    });

    it('applies inherited UserUnit scaling', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [
                ['pageposfrom1' => 1, 'object' => '3 0 R'],
            ],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'obj:2 0 R' => [
                        'value' => [
                            '/Type' => '/Pages',
                            '/MediaBox' => [0, 0, 300, 400],
                            '/UserUnit' => 2,
                        ],
                    ],
                    'obj:3 0 R' => [
                        'value' => [
                            '/Type' => '/Page',
                            '/Parent' => '2 0 R',
                        ],
                    ],
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        $size = $pending->pageSize();

        expect($size->width)->toBe(600.0)
            ->and($size->height)->toBe(800.0)
            ->and($size->userUnit)->toBe(2.0);
    });

    it('includes the document page count when a page does not exist', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [
                ['pageposfrom1' => 1, 'object' => '3 0 R'],
                ['pageposfrom1' => 2, 'object' => '4 0 R'],
            ],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                [
                    'trailer' => ['value' => []],
                ],
            ],
        ]);

        expect(fn (): Johind\Collate\PageSize => $pending->pageSize(99))
            ->toThrow(InvalidArgumentException::class, 'Collate: page 99 does not exist in the document (document has 2 pages).');
    });

    it('converts point dimensions to inches and millimeters', function (): void {
        $size = new Johind\Collate\PageSize(width: 72.0, height: 144.0);

        expect($size->widthInInches())->toBe(1.0)
            ->and($size->heightInInches())->toBe(2.0)
            ->and($size->widthInMillimeters())->toBe(25.4)
            ->and($size->heightInMillimeters())->toBe(50.8);
    });

    it('rejects negative page dimensions', function (): void {
        expect(fn (): Johind\Collate\PageSize => new Johind\Collate\PageSize(width: -1.0, height: 144.0))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects non-positive user unit values', function (): void {
        expect(fn (): Johind\Collate\PageSize => new Johind\Collate\PageSize(width: 72.0, height: 144.0, userUnit: 0.0))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('withoutMetadata()', function (): void {
    it('sets the stripMetadata flag', function (): void {
        $pending = makeCollate()->open('doc.pdf')->withoutMetadata();

        expect(getProperty($pending, 'stripMetadata'))->toBeTrue();
    });

    it('is chainable', function (): void {
        $pending = makeCollate()->open('doc.pdf');

        expect($pending->withoutMetadata())->toBe($pending);
    });

    it('throws when called after withMetadata()', function (): void {
        expect(fn () => makeCollate()->open('doc.pdf')->withMetadata(title: 'Test')->withoutMetadata())
            ->toThrow(BadMethodCallException::class);
    });

    it('throws when called after withMetadata() receives a metadata object', function (): void {
        $metadata = new Johind\Collate\PdfMetadata(title: 'Test');

        expect(fn () => makeCollate()->open('doc.pdf')->withMetadata($metadata)->withoutMetadata())
            ->toThrow(BadMethodCallException::class);
    });

    it('throws after withMetadata() has been explicitly called with no values', function (): void {
        expect(fn () => makeCollate()->open('doc.pdf')->withMetadata()->withoutMetadata())
            ->toThrow(BadMethodCallException::class);
    });

    it('prevents withMetadata() from being called after', function (): void {
        expect(fn () => makeCollate()->open('doc.pdf')->withoutMetadata()->withMetadata(title: 'Test'))
            ->toThrow(BadMethodCallException::class);
    });

    it('does not clear source qpdf JSON cache', function (): void {
        $json = [
            'pages' => [],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                ['trailer' => ['value' => []]],
            ],
        ];
        $pending = pendingWithQpdfJson($json);

        $pending->withoutMetadata();

        expect(getProperty($pending, 'qpdfJsonCache'))->toBe($json);
    });
});

describe('optimize()', function (): void {
    it('sets the optimize flag', function (): void {
        $pending = makeCollate()->open('doc.pdf')->optimize();

        expect(getProperty($pending, 'optimize'))->toBeTrue();
    });

    it('is chainable', function (): void {
        $pending = makeCollate()->open('doc.pdf');

        expect($pending->optimize())->toBe($pending);
    });

    it('does not clear source qpdf JSON cache', function (): void {
        $json = [
            'pages' => [],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                ['trailer' => ['value' => []]],
            ],
        ];
        $pending = pendingWithQpdfJson($json);

        $pending->optimize();

        expect(getProperty($pending, 'qpdfJsonCache'))->toBe($json);
    });
});

describe('decrypt()', function (): void {
    it('clears source qpdf JSON cache because the read password changed', function (): void {
        $pending = pendingWithQpdfJson([
            'pages' => [],
            'qpdf' => [
                ['pdfversion' => '1.7'],
                ['trailer' => ['value' => []]],
            ],
        ]);

        $pending->decrypt('secret');

        expect(getProperty($pending, 'qpdfJsonCache'))->toBeNull();
    });
});
