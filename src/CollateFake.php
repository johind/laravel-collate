<?php

declare(strict_types=1);

namespace Johind\Collate;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

class CollateFake extends Collate
{
    /**
     * All recorded operations.
     *
     * @var Collection<int, PendingCollateFake>
     */
    protected Collection $recorded;

    public function __construct()
    {
        parent::__construct(
            binaryPath: 'qpdf',
            disk: null,
            tempDirectory: '',
        );

        $this->recorded = collect();
    }

    /**
     * Start manipulating an existing PDF.
     */
    public function open(string|UploadedFile $file): PendingCollateFake
    {
        $pending = new PendingCollateFake($this, $file);
        $this->recorded->push($pending);

        return $pending;
    }

    /**
     * Open a PDF for reading only (metadata, page count, etc.).
     */
    public function inspect(string|UploadedFile $file): PendingCollateFake
    {
        $pending = new PendingCollateFake($this, $file);
        $this->recorded->push($pending);

        return $pending;
    }

    /**
     * Merge multiple PDFs into a single document.
     */
    public function merge(Closure|string|UploadedFile ...$files): PendingCollateFake
    {
        $pending = new PendingCollateFake($this);

        foreach ($files as $file) {
            if ($file instanceof Closure) {
                $file($pending);
            } else {
                $pending->addPages($file);
            }
        }

        $this->recorded->push($pending);

        return $pending;
    }

    /**
     * Assert that a PDF was saved to the given path.
     */
    public function assertSaved(?string $path = null, ?callable $callback = null): void
    {
        $saved = $this->recorded->filter(fn (PendingCollateFake $p) => $p->wasSaved());

        if ($path === null && $callback === null) {
            PHPUnit::assertTrue($saved->isNotEmpty(), 'Expected a PDF to be saved, but none was.');

            return;
        }

        $matching = $saved->filter(function (PendingCollateFake $p) use ($path, $callback) {
            if ($path !== null && $p->savedPath() !== $path) {
                return false;
            }

            if ($callback !== null && ! $callback($p)) {
                return false;
            }

            return true;
        });

        PHPUnit::assertTrue(
            $matching->isNotEmpty(),
            $path
                ? "Expected a PDF to be saved to [{$path}], but it was not."
                : 'Expected a PDF to be saved matching the given callback, but none matched.',
        );
    }

    /**
     * Assert that no PDFs were saved.
     */
    public function assertNothingSaved(): void
    {
        $saved = $this->recorded->filter(fn (PendingCollateFake $p) => $p->wasSaved());

        PHPUnit::assertTrue($saved->isEmpty(), "Expected no PDFs to be saved, but {$saved->count()} were.");
    }

    /**
     * Assert that a PDF was downloaded.
     */
    public function assertDownloaded(?string $filename = null, ?callable $callback = null): void
    {
        $downloaded = $this->recorded->filter(fn (PendingCollateFake $p) => $p->wasDownloaded());

        if ($filename === null && $callback === null) {
            PHPUnit::assertTrue($downloaded->isNotEmpty(), 'Expected a PDF to be downloaded, but none was.');

            return;
        }

        $matching = $downloaded->filter(function (PendingCollateFake $p) use ($filename, $callback) {
            if ($filename !== null && $p->downloadedFilename() !== $filename) {
                return false;
            }

            if ($callback !== null && ! $callback($p)) {
                return false;
            }

            return true;
        });

        PHPUnit::assertTrue(
            $matching->isNotEmpty(),
            $filename
                ? "Expected a PDF to be downloaded as [{$filename}], but it was not."
                : 'Expected a PDF to be downloaded matching the given callback, but none matched.',
        );
    }

    /**
     * Assert that no PDFs were downloaded.
     */
    public function assertNothingDownloaded(): void
    {
        $downloaded = $this->recorded->filter(fn (PendingCollateFake $p) => $p->wasDownloaded());

        PHPUnit::assertTrue($downloaded->isEmpty(), "Expected no PDFs to be downloaded, but {$downloaded->count()} were.");
    }

    /**
     * Assert that a PDF was streamed.
     */
    public function assertStreamed(?string $filename = null, ?callable $callback = null): void
    {
        $streamed = $this->recorded->filter(fn (PendingCollateFake $p) => $p->wasStreamed());

        if ($filename === null && $callback === null) {
            PHPUnit::assertTrue($streamed->isNotEmpty(), 'Expected a PDF to be streamed, but none was.');

            return;
        }

        $matching = $streamed->filter(function (PendingCollateFake $p) use ($filename, $callback) {
            if ($filename !== null && $p->streamedFilename() !== $filename) {
                return false;
            }

            if ($callback !== null && ! $callback($p)) {
                return false;
            }

            return true;
        });

        PHPUnit::assertTrue(
            $matching->isNotEmpty(),
            $filename
                ? "Expected a PDF to be streamed as [{$filename}], but it was not."
                : 'Expected a PDF to be streamed matching the given callback, but none matched.',
        );
    }

    /**
     * Assert that no PDFs were streamed.
     */
    public function assertNothingStreamed(): void
    {
        $streamed = $this->recorded->filter(fn (PendingCollateFake $p) => $p->wasStreamed());

        PHPUnit::assertTrue($streamed->isEmpty(), "Expected no PDFs to be streamed, but {$streamed->count()} were.");
    }

    /**
     * Assert that a PDF was split.
     */
    public function assertSplit(): void
    {
        $split = $this->recorded->filter(fn (PendingCollateFake $p) => $p->wasSplit());

        PHPUnit::assertTrue($split->isNotEmpty(), 'Expected a PDF to be split, but none was.');
    }

    /**
     * Get all recorded operations.
     *
     * @return Collection<int, PendingCollateFake>
     */
    public function recorded(): Collection
    {
        return $this->recorded;
    }
}
