<?php

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

function pdf_fixture(string $name): string
{
    $source = __DIR__.'/__fixtures__/'.$name;
    $target = 'test-fixtures/'.$name;

    Storage::disk()->put($target, file_get_contents($source));

    return $target;
}

function pdf_page_count(string $path): int
{
    $disk = Storage::disk();

    if ($disk->exists($path)) {
        $tempPath = $disk->path($path);
    } else {
        $tempPath = $path;
    }

    $result = Process::run(['qpdf', '--show-npages', $tempPath]);

    if (! $result->successful()) {
        throw new \RuntimeException('qpdf failed: '.$result->errorOutput());
    }

    return (int) trim($result->output());
}

function pdf_is_encrypted(string $path): bool
{
    $disk = Storage::disk();

    if ($disk->exists($path)) {
        $tempPath = $disk->path($path);
    } else {
        $tempPath = $path;
    }

    $result = Process::run(['qpdf', '--check', $tempPath]);

    return str_contains($result->output(), 'encrypted');
}

function pdf_is_linearized(string $path): bool
{
    $disk = Storage::disk();

    if ($disk->exists($path)) {
        $tempPath = $disk->path($path);
    } else {
        $tempPath = $path;
    }

    $result = Process::run(['qpdf', '--check', $tempPath]);

    return str_contains($result->output(), 'linearized');
}
