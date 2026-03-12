<?php

declare(strict_types=1);

namespace Johind\Collate;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Traits\Macroable;

class Collate
{
    use Macroable;

    public function __construct(
        protected string $binaryPath,
        protected ?string $disk = null,
        protected string $tempDirectory = '',
    ) {}

    /**
     * Set the storage disk for the next operation.
     */
    public function disk(string $disk): static
    {
        $instance = clone $this;
        $instance->disk = $disk;

        return $instance;
    }

    /**
     * Start manipulating an existing PDF.
     */
    public function open(string|UploadedFile $file): PendingCollate
    {
        return new PendingCollate($this, $file);
    }

    /**
     * Open a PDF for reading only (metadata, page count, etc.).
     *
     * Semantically equivalent to open(), but signals that no mutations
     * are intended. Use this instead of open() when you only need to
     * read information from a document.
     */
    public function inspect(string|UploadedFile $file): PendingCollate
    {
        return new PendingCollate($this, $file);
    }

    /**
     * Merge multiple PDFs into a single document.
     */
    public function merge(Closure|string|UploadedFile ...$files): PendingCollate
    {
        $pending = new PendingCollate($this);

        foreach ($files as $file) {
            if ($file instanceof Closure) {
                $file($pending);
            } else {
                $pending->addPages($file);
            }
        }

        return $pending;
    }

    /**
     * Get the configured binary path.
     */
    public function binaryPath(): string
    {
        return $this->binaryPath;
    }

    /**
     * Get the configured disk name.
     */
    public function diskName(): ?string
    {
        return $this->disk;
    }

    /**
     * Get the configured temp directory.
     */
    public function tempDirectory(): string
    {
        return $this->tempDirectory;
    }
}
