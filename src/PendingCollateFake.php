<?php

declare(strict_types=1);

namespace Johind\Collate;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PendingCollateFake extends PendingCollate
{
    protected ?string $savedTo = null;

    protected ?string $downloadedAs = null;

    protected ?string $streamedAs = null;

    protected bool $wasSplit = false;

    /**
     * Get the source file path.
     */
    public function sourcePath(): ?string
    {
        return $this->source;
    }

    /**
     * Get the page selection.
     */
    public function selectedPages(): ?string
    {
        return $this->pageSelection;
    }

    /**
     * Get the additions.
     *
     * @return array<int, array{file: string, pages: string|null}>
     */
    public function additions(): array
    {
        return $this->additions;
    }

    /**
     * Whether encryption was configured.
     */
    public function isEncrypted(): bool
    {
        return $this->encryption !== null;
    }

    /**
     * Whether linearization was requested.
     */
    public function isLinearized(): bool
    {
        return $this->linearize;
    }

    /**
     * Whether flattening was requested.
     */
    public function isFlattened(): bool
    {
        return $this->flatten;
    }

    /**
     * Get the rotations applied.
     *
     * @return list<array{degrees: int, pages: string}>
     */
    public function rotations(): array
    {
        return $this->rotations;
    }

    /**
     * Whether the document was saved.
     */
    public function wasSaved(): bool
    {
        return $this->savedTo !== null;
    }

    /**
     * The path it was saved to.
     */
    public function savedPath(): ?string
    {
        return $this->savedTo;
    }

    /**
     * Whether the document was downloaded.
     */
    public function wasDownloaded(): bool
    {
        return $this->downloadedAs !== null;
    }

    /**
     * The filename it was downloaded as.
     */
    public function downloadedFilename(): ?string
    {
        return $this->downloadedAs;
    }

    /**
     * Whether the document was streamed.
     */
    public function wasStreamed(): bool
    {
        return $this->streamedAs !== null;
    }

    /**
     * The filename it was streamed as.
     */
    public function streamedFilename(): ?string
    {
        return $this->streamedAs;
    }

    /**
     * Whether the document was split.
     */
    public function wasSplit(): bool
    {
        return $this->wasSplit;
    }

    public function save(string $path, ?string $disk = null): bool
    {
        $this->savedTo = $path;

        return true;
    }

    public function download(string $filename = 'document.pdf'): StreamedResponse
    {
        $this->downloadedAs = $filename;

        return new StreamedResponse(fn () => null, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function stream(string $filename = 'document.pdf'): StreamedResponse
    {
        $this->streamedAs = $filename;

        return new StreamedResponse(fn () => null, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    public function content(): string
    {
        return 'fake-pdf-content';
    }

    public function split(string $path): Collection
    {
        $this->wasSplit = true;
        $count = $this->pageCount();

        return collect(range(1, $count))->map(fn ($page) => str_replace('{page}', (string) $page, $path)
        );
    }

    public function pageCount(): int
    {
        // In the fake, we assume every file contains 3 pages so that
        // it remains consistent with split() and reflects the summing
        // behavior of the real implementation.
        $files = ($this->source !== null ? 1 : 0) + count($this->additions);

        return $files * 3;
    }

    public function toResponse($request, ?string $filename = null, string $disposition = 'inline'): StreamedResponse
    {
        return $disposition === 'attachment'
            ? $this->download($filename ?? 'document.pdf')
            : $this->stream($filename ?? 'document.pdf');
    }

    /**
     * Resolve file paths without touching the filesystem.
     */
    protected function resolveFilePath(string|UploadedFile $file): string
    {
        if ($file instanceof UploadedFile) {
            return $file->getClientOriginalName();
        }

        return $file;
    }
}
