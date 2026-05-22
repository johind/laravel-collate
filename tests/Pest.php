<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Johind\Collate\Collate;
use Johind\Collate\PendingCollate;
use Johind\Collate\Tests\TestCase;

uses(TestCase::class)->in('.');

/**
 * Skip the current Pest test from helper code.
 */
function skipTest(string $message): void
{
    /** @phpstan-ignore method.notFound */
    test()->skip($message);
}

/**
 * Resolve the absolute path to a test fixture file.
 */
function fixturePath(string $file): string
{
    return __DIR__.'/__fixtures__/'.$file;
}

/**
 * Read a file fixture as a string, failing loudly if it cannot be read.
 */
function fixtureContents(string $file): string
{
    return fileContents(fixturePath($file));
}

/**
 * Read a file as a string, failing loudly if it cannot be read.
 */
function fileContents(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Failed to read file '.$path);
    }

    return $contents;
}

/**
 * Read a stored file as a string, failing loudly if it cannot be read.
 */
function storageContents(string $path, ?string $disk = null): string
{
    $contents = Storage::disk($disk)->get($path);

    if (! is_string($contents)) {
        throw new RuntimeException('Failed to read stored file '.$path);
    }

    return $contents;
}

/**
 * Encode a value as JSON, failing loudly if encoding fails.
 */
function jsonString(mixed $value): string
{
    $json = json_encode($value);

    if ($json === false) {
        throw new RuntimeException('Failed to encode value as JSON.');
    }

    return $json;
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
 * Read a protected string property value via reflection.
 */
function getStringProperty(object $obj, string $property): string
{
    $value = getProperty($obj, $property);

    if (! is_string($value)) {
        throw new RuntimeException('Expected '.$property.' to be a string.');
    }

    return $value;
}

/**
 * Read a protected array property value via reflection.
 *
 * @return array<mixed>
 */
function getArrayProperty(object $obj, string $property): array
{
    $value = getProperty($obj, $property);

    if (! is_array($value)) {
        throw new RuntimeException('Expected '.$property.' to be an array.');
    }

    return $value;
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
    $command = $method->invoke($pending, $output, $pageOverride);

    if (! is_array($command) || array_any($command, fn (mixed $part): bool => ! is_string($part))) {
        throw new RuntimeException('Expected buildCommand() to return a list of strings.');
    }

    $command = array_values($command);

    /** @var list<string> $command */
    return $command;
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

    /** @var array<string, mixed> $decoded */
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

    file_put_contents($tempFile, storageContents($path, $disk));

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
    $pageInfo = qpdfPageInfo($json, $page);

    return qpdfObjectValue($json, $pageInfo['object']);
}

/**
 * Return the qpdf page metadata for a page.
 *
 * @param  array<string, mixed>  $json
 * @return array{object: string, pageposfrom1?: mixed}
 */
function qpdfPageInfo(array $json, int $page = 1): array
{
    foreach (qpdfPages($json) as $candidate) {
        if (($candidate['pageposfrom1'] ?? null) !== $page) {
            continue;
        }

        if (! is_string($candidate['object'] ?? null)) {
            throw new RuntimeException('Could not read page '.$page.' object reference from qpdf JSON.');
        }

        /** @var array{object: string, pageposfrom1?: mixed} $candidate */
        return $candidate;
    }

    throw new RuntimeException('Could not find page '.$page.' in qpdf JSON.');
}

/**
 * Return qpdf page entries.
 *
 * @param  array<string, mixed>  $json
 * @return list<array<string, mixed>>
 */
function qpdfPages(array $json): array
{
    $pages = $json['pages'] ?? null;

    if (! is_array($pages)) {
        throw new RuntimeException('Could not read pages from qpdf JSON.');
    }

    /** @var list<array<string, mixed>> $pages */
    return $pages;
}

/**
 * Return the qpdf object table.
 *
 * @param  array<string, mixed>  $json
 * @return array<string, mixed>
 */
function qpdfObjects(array $json): array
{
    $qpdf = $json['qpdf'] ?? null;

    if (! is_array($qpdf)) {
        throw new RuntimeException('Could not read qpdf array from qpdf JSON.');
    }

    $objects = $qpdf[1] ?? null;

    if (! is_array($objects)) {
        throw new RuntimeException('Could not read object table from qpdf JSON.');
    }

    /** @var array<string, mixed> $objects */
    return $objects;
}

/**
 * Return a qpdf object's value dictionary.
 *
 * @param  array<string, mixed>  $json
 * @return array<string, mixed>
 */
function qpdfObjectValue(array $json, string $ref): array
{
    $object = qpdfObjects($json)['obj:'.$ref] ?? null;
    $values = is_array($object) ? ($object['value'] ?? null) : null;

    if (! is_array($values)) {
        throw new RuntimeException('Could not read object '.$ref.' values from qpdf JSON.');
    }

    /** @var array<string, mixed> $values */
    return $values;
}

/**
 * Safely traverse nested qpdf JSON values.
 *
 * @param  array<mixed>  $values
 */
function qpdfValue(array $values, int|string ...$keys): mixed
{
    $current = $values;

    foreach ($keys as $key) {
        if (! is_array($current) || ! array_key_exists($key, $current)) {
            return null;
        }

        $current = $current[$key];
    }

    return $current;
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

    return array_any($value, fn ($child): bool => arrayContainsKeyRecursive($child, $key));
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
