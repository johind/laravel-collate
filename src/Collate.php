<?php

namespace Johind\Collate;

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
     * Merge multiple PDFs into a single document.
     */
    public function merge(string|UploadedFile ...$files): PendingCollate
    {
        $pending = new PendingCollate($this);

        foreach ($files as $file) {
            $pending->addPage($file);
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
