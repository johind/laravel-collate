<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::put('doc.pdf', file_get_contents(fixturePath('single-page.pdf')));
});

describe('optimize()', function (): void {
    it('appends the optimization flags to the command', function (): void {
        $pending = makeCollate()->open('doc.pdf')->optimize();

        $command = buildCommand($pending);

        expect($command)->toContain('--object-streams=generate')
            ->and($command)->toContain('--remove-unreferenced-resources=yes')
            ->and($command)->toContain('--recompress-flate')
            ->and($command)->toContain('--compression-level=9')
            ->and($command)->not->toContain('--compress-streams=y');
    });

    it('does not append optimization flags by default', function (): void {
        $pending = makeCollate()->open('doc.pdf');

        $command = buildCommand($pending);

        expect($command)->not->toContain('--object-streams=generate')
            ->and($command)->not->toContain('--compress-streams=y')
            ->and($command)->not->toContain('--remove-unreferenced-resources=yes')
            ->and($command)->not->toContain('--recompress-flate')
            ->and($command)->not->toContain('--compression-level=9');
    });

    it('omits object stream generation when linearizing', function (): void {
        $pending = makeCollate()->open('doc.pdf')->optimize()->linearize();

        $command = buildCommand($pending);

        expect($command)->not->toContain('--object-streams=generate')
            ->and($command)->toContain('--remove-unreferenced-resources=yes')
            ->and($command)->toContain('--recompress-flate')
            ->and($command)->toContain('--compression-level=9')
            ->and($command)->toContain('--linearize');
    });
});
