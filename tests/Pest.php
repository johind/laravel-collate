<?php

declare(strict_types=1);

use Johind\Collate\Collate;
use Johind\Collate\PendingCollate;
use Johind\Collate\Tests\TestCase;

uses(TestCase::class)->in('.');

/**
 * Resolve the absolute path to a test fixture file.
 */
function fixturePath(string $file): string
{
    return __DIR__.'/__fixtures__/'.$file;
}

/**
 * Create a Collate instance pointing at the local disk.
 */
function makeCollate(string $disk = 'local'): Collate
{
    return new Collate('qpdf', $disk, sys_get_temp_dir().'/collate-tests');
}

/**
 * Read a protected property value via reflection.
 */
function getProperty(object $obj, string $property): mixed
{
    return new ReflectionProperty($obj, $property)->getValue($obj);
}

/**
 * Invoke the protected buildCommand() method directly, bypassing Process::run.
 * This lets CommandBuilderTest assert on exact qpdf flags without needing
 * a real binary or a faked process.
 *
 * @return list<string>
 */
function buildCommand(PendingCollate $pending, string $output = '/tmp/test-output.pdf', ?string $pageOverride = null): array
{
    $method = new ReflectionMethod(PendingCollate::class, 'buildCommand');

    return $method->invoke($pending, $output, $pageOverride);
}

/**
 * Check whether qpdf is available on the current system.
 * Used to conditionally skip integration tests.
 */
function qpdfAvailable(): bool
{
    $output = shell_exec('command -v qpdf');

    return is_string($output) && mb_trim($output) !== '';
}
