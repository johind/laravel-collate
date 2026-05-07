<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
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
 * Create a PendingCollate instance with cached qpdf JSON for inspection unit tests.
 *
 * @param  array<string, mixed>  $json
 */
function pendingWithQpdfJson(array $json): PendingCollate
{
    $pending = new PendingCollate(makeCollate());

    new ReflectionProperty($pending, 'source')->setValue($pending, sys_get_temp_dir().'/cached.pdf');
    new ReflectionProperty($pending, 'qpdfJsonCache')->setValue($pending, $json);

    return $pending;
}

/**
 * Read qpdf JSON for a filesystem path.
 *
 * @return array<string, mixed>
 */
function qpdfJson(string $path): array
{
    $json = shell_exec('qpdf --json=2 '.escapeshellarg($path));
    $decoded = json_decode((string) $json, true);

    if (! is_array($decoded)) {
        throw new RuntimeException('Failed to decode qpdf JSON for '.$path);
    }

    return $decoded;
}

/**
 * Read qpdf JSON for a file on the configured storage disk.
 *
 * @return array<string, mixed>
 */
function storageQpdfJson(string $path, ?string $disk = null): array
{
    $tempFile = tempnam(sys_get_temp_dir(), 'collate-qpdf-json-');

    if ($tempFile === false) {
        throw new RuntimeException('Failed to create temp file for qpdf JSON.');
    }

    file_put_contents($tempFile, Storage::disk($disk)->get($path));

    try {
        return qpdfJson($tempFile);
    } finally {
        @unlink($tempFile);
    }
}

/**
 * Return the qpdf object values for a page.
 *
 * @param  array<string, mixed>  $json
 * @return array<string, mixed>
 */
function qpdfPageValues(array $json, int $page = 1): array
{
    $pageInfo = collect($json['pages'] ?? [])
        ->first(fn (array $candidate): bool => ($candidate['pageposfrom1'] ?? null) === $page);

    if (! is_array($pageInfo) || ! is_string($pageInfo['object'] ?? null)) {
        throw new RuntimeException('Could not find page '.$page.' in qpdf JSON.');
    }

    $objects = $json['qpdf'][1] ?? null;
    $pageObject = is_array($objects) ? ($objects['obj:'.$pageInfo['object']] ?? null) : null;
    $values = is_array($pageObject) ? ($pageObject['value'] ?? null) : null;

    if (! is_array($values)) {
        throw new RuntimeException('Could not read page '.$page.' object values from qpdf JSON.');
    }

    return $values;
}

/**
 * Recursively determine whether a decoded JSON value contains an object key.
 */
function arrayContainsKeyRecursive(mixed $value, string $key): bool
{
    if (! is_array($value)) {
        return false;
    }

    if (array_key_exists($key, $value)) {
        return true;
    }

    foreach ($value as $child) {
        if (arrayContainsKeyRecursive($child, $key)) {
            return true;
        }
    }

    return false;
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
