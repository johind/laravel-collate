<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::put('doc.pdf', fixtureContents('single-page.pdf'));
});

describe('encrypt()', function (): void {
    it('throws for an invalid bit length', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->encrypt('password', bitLength: 512))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts 40-bit encryption', function (): void {
        $pending = makeCollate()->open('doc.pdf')->encrypt('password', bitLength: 40);

        expect(qpdfValue(getArrayProperty($pending, 'encryption'), 'bit_length'))->toBe(40);
    });

    it('accepts 128-bit encryption', function (): void {
        $pending = makeCollate()->open('doc.pdf')->encrypt('password', bitLength: 128);

        expect(qpdfValue(getArrayProperty($pending, 'encryption'), 'bit_length'))->toBe(128);
    });

    it('accepts 256-bit encryption', function (): void {
        $pending = makeCollate()->open('doc.pdf')->encrypt('password', bitLength: 256);

        expect(qpdfValue(getArrayProperty($pending, 'encryption'), 'bit_length'))->toBe(256);
    });

    it('generates a random owner password distinct from the user password', function (): void {
        $first = getArrayProperty(makeCollate()->open('doc.pdf')->encrypt('secret'), 'encryption');
        $second = getArrayProperty(makeCollate()->open('doc.pdf')->encrypt('secret'), 'encryption');

        expect($first['user_password'])->toBe('secret')
            ->and($first['owner_password'])->not->toBeEmpty()
            ->and($first['owner_password'])->not->toBe('secret')
            ->and($first['owner_password'])->not->toBe($second['owner_password']);
    });

    it('rejects passwords containing line breaks or NUL bytes', function (string $user, ?string $owner): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->encrypt($user, $owner))
            ->toThrow(InvalidArgumentException::class, 'line breaks');
    })->with([
        'newline in user password' => ["a\nb", null],
        'carriage return in owner password' => ['a', "b\rc"],
        'NUL in owner password' => ['a', "b\0c"],
    ]);

    it('rejects decrypt passwords containing line breaks', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->decrypt("a\nb"))
            ->toThrow(InvalidArgumentException::class, 'line breaks');
    });

    it('accepts separate user and owner passwords', function (): void {
        $pending = makeCollate()->open('doc.pdf')->encrypt('user', 'owner');
        $encryption = getArrayProperty($pending, 'encryption');

        expect($encryption['user_password'])->toBe('user')
            ->and($encryption['owner_password'])->toBe('owner');
    });
});

describe('restrict()', function (): void {
    it('throws without a prior encrypt() call', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->restrict('print'))
            ->toThrow(BadMethodCallException::class);
    });

    it('throws for an unrecognised permission name', function (): void {
        expect(fn (): Johind\Collate\PendingCollate => makeCollate()->open('doc.pdf')->encrypt('password')->restrict('invalid-perm'))
            ->toThrow(InvalidArgumentException::class, 'invalid-perm');
    });

    it('accepts all eight valid permission names without throwing', function (): void {
        $valid = ['print', 'modify', 'extract', 'annotate', 'assemble', 'print-highres', 'form', 'modify-other'];
        $base = makeCollate()->open('doc.pdf')->encrypt('password');

        foreach ($valid as $permission) {
            $pending = (clone $base)->restrict($permission);
            expect(getProperty($pending, 'restrictions'))->toContain($permission);
        }
    });

    it('stores the requested permissions', function (): void {
        $pending = makeCollate()->open('doc.pdf')
            ->encrypt('password')
            ->restrict('print', 'extract');

        expect(getProperty($pending, 'restrictions'))->toBe(['print', 'extract']);
    });

    it('can be called multiple times to accumulate permissions', function (): void {
        $pending = makeCollate()->open('doc.pdf')
            ->encrypt('password')
            ->restrict('print')
            ->restrict('modify');

        expect(getProperty($pending, 'restrictions'))->toBe(['print', 'modify']);
    });

    it('deduplicates repeated permissions', function (): void {
        $pending = makeCollate()->open('doc.pdf')
            ->encrypt('password')
            ->restrict('print')
            ->restrict('print');

        expect(getProperty($pending, 'restrictions'))->toBe(['print']);
    });
});

describe('decrypt()', function (): void {
    it('stores the decrypt password', function (): void {
        $pending = makeCollate()->open('doc.pdf')->decrypt('secret');

        expect(getProperty($pending, 'decryptPassword'))->toBe('secret');
    });
});
