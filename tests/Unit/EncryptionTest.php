<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

describe('encrypt()', function () {
    it('throws for an invalid bit length', function () {
        expect(fn () => makeCollate()->open('doc.pdf')->encrypt('password', bitLength: 512))
            ->toThrow(InvalidArgumentException::class);
    });

    it('accepts 40-bit encryption', function () {
        $pending = makeCollate()->open('doc.pdf')->encrypt('password', bitLength: 40);

        expect(getProperty($pending, 'encryption')['bit_length'])->toBe(40);
    });

    it('accepts 128-bit encryption', function () {
        $pending = makeCollate()->open('doc.pdf')->encrypt('password', bitLength: 128);

        expect(getProperty($pending, 'encryption')['bit_length'])->toBe(128);
    });

    it('accepts 256-bit encryption', function () {
        $pending = makeCollate()->open('doc.pdf')->encrypt('password', bitLength: 256);

        expect(getProperty($pending, 'encryption')['bit_length'])->toBe(256);
    });

    it('defaults the owner password to the user password', function () {
        $pending = makeCollate()->open('doc.pdf')->encrypt('secret');
        $encryption = getProperty($pending, 'encryption');

        expect($encryption['user_password'])->toBe('secret')
            ->and($encryption['owner_password'])->toBe('secret');
    });

    it('accepts separate user and owner passwords', function () {
        $pending = makeCollate()->open('doc.pdf')->encrypt('user', 'owner');
        $encryption = getProperty($pending, 'encryption');

        expect($encryption['user_password'])->toBe('user')
            ->and($encryption['owner_password'])->toBe('owner');
    });
});

describe('restrict()', function () {
    it('throws without a prior encrypt() call', function () {
        expect(fn () => makeCollate()->open('doc.pdf')->restrict('print'))
            ->toThrow(BadMethodCallException::class);
    });

    it('throws for an unrecognised permission name', function () {
        expect(fn () => makeCollate()->open('doc.pdf')->encrypt('password')->restrict('invalid-perm'))
            ->toThrow(InvalidArgumentException::class, 'invalid-perm');
    });

    it('accepts all eight valid permission names without throwing', function () {
        $valid = ['print', 'modify', 'extract', 'annotate', 'assemble', 'print-highres', 'form', 'modify-other'];
        $base = makeCollate()->open('doc.pdf')->encrypt('password');

        foreach ($valid as $permission) {
            $pending = (clone $base)->restrict($permission);
            expect(getProperty($pending, 'restrictions'))->toContain($permission);
        }
    });

    it('stores the requested permissions', function () {
        $pending = makeCollate()->open('doc.pdf')
            ->encrypt('password')
            ->restrict('print', 'extract');

        expect(getProperty($pending, 'restrictions'))->toBe(['print', 'extract']);
    });

    it('can be called multiple times to accumulate permissions', function () {
        $pending = makeCollate()->open('doc.pdf')
            ->encrypt('password')
            ->restrict('print')
            ->restrict('modify');

        expect(getProperty($pending, 'restrictions'))->toBe(['print', 'modify']);
    });

    it('deduplicates repeated permissions', function () {
        $pending = makeCollate()->open('doc.pdf')
            ->encrypt('password')
            ->restrict('print')
            ->restrict('print');

        expect(getProperty($pending, 'restrictions'))->toBe(['print']);
    });
});

describe('decrypt()', function () {
    it('stores the decrypt password', function () {
        $pending = makeCollate()->open('doc.pdf')->decrypt('secret');

        expect(getProperty($pending, 'decryptPassword'))->toBe('secret');
    });
});
