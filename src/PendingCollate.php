<?php

namespace Johind\Collate;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Johind\Collate\Exceptions\ProcessFailedException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PendingCollate implements Responsable
{
    use Conditionable, Macroable;

    /**
     * Map of valid permission names to their correct qpdf deny flag.
     *
     * qpdf does not use a uniform value for all permission flags —
     * some accept 'none', others accept 'n'. This map encodes the
     * correct deny form for each supported permission.
     *
     * @var array<string, string>
     */
    protected const array RESTRICTIONS = [
        'print' => '--print=none',
        'modify' => '--modify=none',
        'extract' => '--extract=n',
        'annotate' => '--annotate=n',
        'assemble' => '--assemble=n',
        'print-highres' => '--print-highres=n',
        'form' => '--form=n',
        'modify-other' => '--modify-other=n',
    ];

    /**
     * The source file being manipulated.
     */
    protected ?string $source = null;

    /**
     * Pages to add from other files.
     *
     * @var array<int, array{file: string, pages: string|null}>
     */
    protected array $additions = [];

    /**
     * Pages to keep from the source document.
     */
    protected ?string $pageSelection = null;

    /**
     * Encryption settings.
     *
     * @var array{user_password: string, owner_password: string, bit_length: int}|null
     */
    protected ?array $encryption = null;

    /**
     * Permissions to restrict on the document.
     *
     * @var list<string>
     */
    protected array $restrictions = [];

    /**
     * Whether to linearize the output.
     */
    protected bool $linearize = false;

    /**
     * Rotation commands to apply.
     *
     * @var list<array{degrees: int, pages: string}>
     */
    protected array $rotations = [];

    /**
     * Overlay file path.
     */
    protected ?string $overlayFile = null;

    /**
     * Underlay file path.
     */
    protected ?string $underlayFile = null;

    /**
     * Password to decrypt the source document.
     */
    protected ?string $decryptPassword = null;

    /**
     * Whether to flatten form fields and annotations.
     */
    protected bool $flatten = false;

    /**
     * Metadata fields to set on the output PDF.
     *
     * @var array<string, string>
     */
    protected array $metadata = [];

    /**
     * Temporary files downloaded from remote disks that should be cleaned up.
     *
     * @var list<string>
     */
    protected array $tempInputFiles = [];

    public function __construct(
        protected Collate $collate,
        string|UploadedFile|null $source = null,
    ) {
        if ($source !== null) {
            $this->source = $this->resolveFilePath($source);
        }
    }

    public function __destruct()
    {
        $this->cleanupTempInputFiles();
    }

    /**
     * Add a complete file, or a single page from a file.
     */
    public function addPage(
        string|UploadedFile $file,
        ?int $pageNumber = null,
    ): static {
        $this->additions[] = [
            'file' => $this->resolveFilePath($file),
            'pages' => $pageNumber !== null ? (string) $pageNumber : null,
        ];

        return $this;
    }

    /**
     * Add a range of pages from a file, or multiple files at once.
     *
     * @param  string|array<int, string|\Illuminate\Http\UploadedFile>|\Illuminate\Http\UploadedFile  $files
     */
    public function addPages(
        string|array|UploadedFile $files,
        ?string $range = null,
    ): static {
        if (is_array($files)) {
            if ($range !== null) {
                throw new \InvalidArgumentException(
                    'Cannot use range parameter when adding multiple files. '
                    .'Chain multiple addPages() calls with range instead.'
                );
            }

            foreach ($files as $file) {
                $this->addPage($file);
            }

            return $this;
        }

        $this->additions[] = [
            'file' => $this->resolveFilePath($files),
            'pages' => $range,
        ];

        return $this;
    }

    /**
     * Remove a single page by its number.
     */
    public function removePage(int $pageNumber): static
    {
        return $this->removePages([$pageNumber]);
    }

    /**
     * Remove multiple pages or a range (e.g., [1, 3], '5-10', or '1,3,5-8').
     *
     * @param  string|array<int, int|string>  $pages
     */
    public function removePages(string|array $pages): static
    {
        if ($this->source === null) {
            throw new \BadMethodCallException(
                'Collate: cannot call removePages() when no source file is set. Use open() or inspect() first.'
            );
        }

        if ($this->pageSelection !== null) {
            throw new \BadMethodCallException(
                'Collate: cannot call removePages() after onlyPages() or removePages() has already been called.'
            );
        }

        // Normalize the input into a clean, sorted array of integers,
        // so we can process the gaps sequentially from start to finish.
        $items = is_array($pages) ? $pages : explode(',', $pages);

        $pagesToRemove = [];

        // Expand any hyphenated ranges (e.g. '5-10') into individual page
        // numbers before processing. intval alone would silently truncate
        // '5-10' to 5, dropping pages 6 through 10.
        foreach ($items as $item) {
            $item = trim((string) $item);

            if ($item === '' || ! preg_match('/^\d+(-\d+)?$/', $item)) {
                throw new \InvalidArgumentException(
                    "Collate: '{$item}' is not a valid page number or range."
                );
            }

            if (str_contains($item, '-')) {
                [$start, $end] = explode('-', $item, 2);
                array_push($pagesToRemove, ...range((int) $start, (int) $end));
            } else {
                $pagesToRemove[] = (int) $item;
            }
        }

        foreach ($pagesToRemove as $page) {
            if ($page < 1) {
                throw new \InvalidArgumentException(
                    "Collate: page numbers must be at least 1. Got: {$page}."
                );
            }
        }

        sort($pagesToRemove);
        $pagesToRemove = array_unique($pagesToRemove);

        $totalPageCount = $this->getFilePageCount($this->source);

        $keepRanges = [];
        $start = 1;

        // qpdf does not support negative page exclusions (like "!5").
        // We must calculate the positive blocks of pages we want to KEEP.
        // For example: To remove page 3, we must tell qpdf to keep "1-2" and "4-z".
        foreach ($pagesToRemove as $skipPage) {
            if ($skipPage > $totalPageCount) {
                continue;
            }

            if ($skipPage > $start) {
                $endOfKeepRange = $skipPage - 1;

                $keepRanges[] = ($start === $endOfKeepRange)
                    ? (string) $start
                    : "{$start}-{$endOfKeepRange}";
            }

            // Move our internal pointer to the page immediately after the one we just skipped.
            $start = $skipPage + 1;
        }

        // Only append the remainder of the document if there are pages left.
        // 'z' is qpdf's variable for the final page of the document.
        if ($start <= $totalPageCount) {
            $keepRanges[] = "{$start}-z";
        }

        $this->pageSelection = implode(',', $keepRanges);

        return $this;
    }

    /**
     * Keep only the specified pages.
     *
     * @param  string|array<int, int|string>  $pages
     */
    public function onlyPages(string|array $pages): static
    {
        if ($this->source === null) {
            throw new \BadMethodCallException(
                'Collate: cannot call onlyPages() when no source file is set. Use open() or inspect() first.'
            );
        }

        if ($this->pageSelection !== null) {
            throw new \BadMethodCallException(
                'Collate: cannot call onlyPages() after removePages() or onlyPages() has already been called.'
            );
        }

        if (is_array($pages)) {
            $pages = implode(',', $pages);
        }

        $this->pageSelection = $pages;

        return $this;
    }

    /**
     * Encrypt the document and restrict permissions.
     *
     * Note: 40-bit and 128-bit encryption are considered weak and are not
     * recommended for sensitive data. Modern versions of qpdf require an
     * internal flag to allow them, which Collate handles automatically.
     */
    public function encrypt(
        string $userPassword,
        ?string $ownerPassword = null,
        int $bitLength = 256,
    ): static {
        if (! in_array($bitLength, [40, 128, 256], true)) {
            throw new \InvalidArgumentException(
                "Encryption bit length must be 40, 128, or 256. Got: {$bitLength}",
            );
        }

        $this->encryption = [
            'user_password' => $userPassword,
            'owner_password' => $ownerPassword ?? $userPassword,
            'bit_length' => $bitLength,
        ];

        return $this;
    }

    /**
     * Decrypt a password-protected document.
     */
    public function decrypt(string $password): static
    {
        $this->decryptPassword = $password;

        return $this;
    }

    /**
     * Restrict permissions on the document.
     * Must be called after encrypt().
     *
     * Supported permissions: print, modify, extract, annotate,
     * assemble, print-highres, form, modify-other.
     */
    public function restrict(string ...$permissions): static
    {
        if ($this->encryption === null) {
            throw new \BadMethodCallException(
                'Collate: cannot restrict permissions without encryption. Call encrypt() first.'
            );
        }

        foreach ($permissions as $permission) {
            if (! array_key_exists($permission, self::RESTRICTIONS)) {
                $valid = implode(', ', array_keys(self::RESTRICTIONS));
                throw new \InvalidArgumentException(
                    "Collate: '{$permission}' is not a valid permission. Valid permissions are: {$valid}."
                );
            }
        }

        array_push($this->restrictions, ...$permissions);
        $this->restrictions = array_values(array_unique($this->restrictions));

        return $this;
    }

    /**
     * Optimize the PDF for fast web viewing.
     */
    public function linearize(): static
    {
        $this->linearize = true;

        return $this;
    }

    /**
     * Rotate specific pages.
     */
    public function rotate(int $degrees, string $pages = '1-z'): static
    {
        if (! in_array($degrees, [0, 90, 180, 270], true)) {
            throw new \InvalidArgumentException(
                "Rotation degrees must be 0, 90, 180, or 270. Got: {$degrees}",
            );
        }

        $this->rotations[] = [
            'degrees' => $degrees,
            'pages' => $pages,
        ];

        return $this;
    }

    /**
     * Overlay another PDF on top (watermarks, letterheads).
     */
    public function overlay(string|UploadedFile $file): static
    {
        $this->overlayFile = $this->resolveFilePath($file);

        return $this;
    }

    /**
     * Underlay another PDF behind the content (backgrounds).
     */
    public function underlay(string|UploadedFile $file): static
    {
        $this->underlayFile = $this->resolveFilePath($file);

        return $this;
    }

    /**
     * Flatten form fields and annotations.
     */
    public function flatten(): static
    {
        $this->flatten = true;

        return $this;
    }

    /**
     * Read the metadata from the source document.
     */
    public function metadata(): PdfMetadata
    {
        if ($this->source === null) {
            throw new \BadMethodCallException('Collate: cannot read metadata without a source file. Use open() or inspect() first.');
        }

        $command = [
            $this->collate->binaryPath(),
            '--json',
        ];

        if ($this->decryptPassword !== null) {
            $command[] = "--password={$this->decryptPassword}";
        }

        $command[] = $this->source;

        $result = Process::run($command);

        if (! $result->successful()) {
            throw new ProcessFailedException(
                "Collate: failed to read PDF metadata — {$result->errorOutput()}",
                $result->exitCode(),
                $result->errorOutput(),
            );
        }

        $json = json_decode($result->output(), true);
        $qpdfObjects = $json['qpdf'][1] ?? [];
        $info = [];

        // The info ref is stored as e.g. "6 0 R" in the trailer value.
        $infoRef = $qpdfObjects['trailer']['value']['/Info'] ?? null;

        // The object key is "obj:6 0 R" — we must prepend "obj:" to the ref.
        if ($infoRef && isset($qpdfObjects["obj:{$infoRef}"]['value'])) {
            foreach ($qpdfObjects["obj:{$infoRef}"]['value'] as $field => $meta) {
                // Strip the leading "/" from the field key to normalise
                // e.g. "/Title" → "Title" for PdfMetadata::fromArray.
                $key = ltrim($field, '/');

                // qpdf JSON v2 encodes strings with a "u:" prefix.
                if (is_string($meta) && str_starts_with($meta, 'u:')) {
                    $info[$key] = substr($meta, 2);
                } elseif (is_string($meta)) {
                    $info[$key] = $meta;
                }
            }
        }

        return PdfMetadata::fromArray($info);
    }

    /**
     * Set metadata on the output document.
     */
    public function withMetadata(
        ?string $title = null,
        ?string $author = null,
        ?string $subject = null,
        ?string $keywords = null,
        ?string $creator = null,
        ?string $producer = null,
        ?string $creationDate = null,
        ?string $modDate = null,
    ): static {
        $map = [
            'Title' => $title,
            'Author' => $author,
            'Subject' => $subject,
            'Keywords' => $keywords,
            'Creator' => $creator,
            'Producer' => $producer,
            'CreationDate' => $creationDate,
            'ModDate' => $modDate,
        ];

        foreach ($map as $key => $value) {
            if ($value !== null) {
                $this->metadata[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Get the number of pages in the output document.
     */
    public function pageCount(): int
    {
        $total = 0;

        if ($this->source !== null) {
            $count = $this->getFilePageCount($this->source);
            $total += $this->calculateSelectedPageCount($count, $this->pageSelection);
        }

        foreach ($this->additions as $addition) {
            $count = $this->getFilePageCount($addition['file']);
            $total += $this->calculateSelectedPageCount($count, $addition['pages']);
        }

        return $total;
    }

    /**
     * Get the page count of a specific file.
     */
    protected function getFilePageCount(string $file): int
    {
        $command = [
            $this->collate->binaryPath(),
            '--show-npages',
        ];

        if ($this->decryptPassword !== null) {
            $command[] = "--password={$this->decryptPassword}";
        }

        $command[] = $file;

        $result = Process::run($command);

        if (! $result->successful()) {
            throw new ProcessFailedException(
                "Collate: failed to count pages for file '{$file}' — {$result->errorOutput()}",
                $result->exitCode(),
                $result->errorOutput(),
            );
        }

        return (int) trim($result->output());
    }

    /**
     * Calculate how many pages a selection string would produce from a file.
     */
    protected function calculateSelectedPageCount(int $totalInFile, ?string $selection): int
    {
        if ($selection === null || $selection === '1-z') {
            return $totalInFile;
        }

        $count = 0;
        $items = explode(',', $selection);

        foreach ($items as $item) {
            $item = trim($item);

            if (str_contains($item, '-')) {
                [$startStr, $endStr] = explode('-', $item, 2);
                $start = ($startStr === 'z') ? $totalInFile : (int) $startStr;
                $end = ($endStr === 'z') ? $totalInFile : (int) $endStr;

                if ($startStr === 'z' || $endStr === 'z' || ($start <= $totalInFile)) {
                    // qpdf supports reverse ranges like z-1, which results
                    // in $totalInFile pages.
                    if ($start > $end) {
                        $count += ($start - $end + 1);
                    } else {
                        $actualStart = max(1, $start);
                        $actualEnd = min($end, $totalInFile);
                        $count += max(0, $actualEnd - $actualStart + 1);
                    }
                }
            } else {
                $page = ($item === 'z') ? $totalInFile : (int) $item;
                if ($page >= 1 && $page <= $totalInFile) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Save the final PDF to a path on the configured disk.
     *
     * Pass a disk name to save to a different disk than the one configured
     * on the Collate instance, for example to read from local and write to S3.
     */
    public function save(string $path, ?string $disk = null): bool
    {
        $tempOutput = $this->process();
        $disk = Storage::disk($disk ?? $this->collate->diskName());

        try {
            // Use a stream rather than file_get_contents to avoid loading
            // the entire file into PHP memory, which would OOM on large PDFs.
            $stream = fopen($tempOutput, 'r');

            if ($stream === false) {
                throw new \RuntimeException("Collate: failed to open temp file for reading: {$tempOutput}");
            }

            return $disk->put($path, $stream);
        } finally {
            @unlink($tempOutput);
        }
    }

    /**
     * Return a download response to the browser.
     */
    public function download(
        string $filename = 'document.pdf',
    ): StreamedResponse {
        return $this->toResponse(request(), $filename, 'attachment');
    }

    /**
     * Stream the PDF inline to the browser.
     */
    public function stream(string $filename = 'document.pdf'): StreamedResponse
    {
        return $this->toResponse(request(), $filename, 'inline');
    }

    /**
     * Get the raw PDF contents as a string.
     */
    public function content(): string
    {
        $tempOutput = $this->process();

        try {
            $content = file_get_contents($tempOutput);

            if ($content === false) {
                throw new \RuntimeException("Collate: failed to read temp file: {$tempOutput}");
            }

            return $content;
        } finally {
            @unlink($tempOutput);
        }
    }

    /**
     * Split each page into a separate file, saved to the configured disk.
     *
     * The path may contain a {page} placeholder for the page number.
     *
     * @return Collection<int, string>
     */
    public function split(string $path): Collection
    {
        $dir = $this->collate->tempDirectory();

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Run the full pipeline first so that all operations (page selection,
        // rotations, encryption, overlays, etc.) are applied before splitting.
        // The processed file becomes the input to the split command.
        $processedFile = $this->process();

        $prefix = $dir.'/'.Str::uuid();
        $command = [$this->collate->binaryPath()];

        if ($this->encryption) {
            $command[] = "--password={$this->encryption['owner_password']}";
        }

        $command[] = $processedFile;
        $command[] = '--split-pages';
        $command[] = "{$prefix}-%d.pdf";

        $splitFiles = [];

        try {
            $result = Process::run($command);

            if (! $result->successful()) {
                throw new ProcessFailedException(
                    "Collate: qpdf split failed — {$result->errorOutput()}",
                    $result->exitCode(),
                    $result->errorOutput(),
                );
            }

            $disk = Storage::disk($this->collate->diskName());
            $paths = collect();

            // qpdf's %d produces zero-padded page numbers (e.g. "01", "02"),
            // so we use glob to discover the actual filenames instead of
            // guessing the format with an incrementing integer counter.
            $splitFiles = glob("{$prefix}-*.pdf") ?: [];
            natsort($splitFiles);

            foreach ($splitFiles as $page => $tempFile) {
                $pageNumber = $page + 1;
                $destination = str_replace('{page}', (string) $pageNumber, $path);

                // Stream each page file to disk rather than loading it into
                // memory — individual pages of a large document can still be
                // several megabytes each.
                $disk->put($destination, fopen($tempFile, 'r'));

                $paths->push($destination);
            }

            return $paths;
        } finally {
            @unlink($processedFile);
            foreach ($splitFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Create an HTTP response for the given disposition type.
     */
    public function toResponse(
        $request,
        ?string $filename = null,
        string $disposition = 'inline',
    ): StreamedResponse {
        $filename ??= 'document.pdf';
        $tempOutput = $this->process();

        $response = response()->streamDownload(
            function () use ($tempOutput) {
                // Stream the file to the output buffer in chunks rather than
                // reading the whole PDF into a PHP string first.
                try {
                    $stream = fopen($tempOutput, 'r');
                    fpassthru($stream);
                    fclose($stream);
                } finally {
                    @unlink($tempOutput);
                }
            },
            $filename,
            ['Content-Type' => 'application/pdf'],
        );

        // streamDownload() always sets Content-Disposition to 'attachment'.
        // We override it here to support both inline and attachment dispositions.
        $response->headers->set(
            'Content-Disposition',
            "{$disposition}; filename=\"{$filename}\""
        );

        return $response;
    }

    /**
     * Run the qpdf process and return the path to the temporary output file.
     */
    protected function process(?string $pageOverride = null): string
    {
        $tempOutput = $this->tempFilePath();
        $command = $this->buildCommand($tempOutput, $pageOverride);

        try {
            $result = Process::run($command);

            if (! $result->successful()) {
                @unlink($tempOutput);
                throw new ProcessFailedException(
                    "Collate: qpdf failed — {$result->errorOutput()}",
                    $result->exitCode(),
                    $result->errorOutput(),
                );
            }

            if (! empty($this->metadata)) {
                try {
                    $this->applyMetadata($tempOutput);
                } catch (\Throwable $e) {
                    @unlink($tempOutput);
                    throw $e;
                }
            }

            return $tempOutput;
        } finally {
            // We no longer clean up temp input files here, as it would break
            // subsequent output calls (save(), content(), etc.) when using
            // remote source files. Cleanup happens in __destruct().
        }
    }

    /**
     * Apply metadata fields to an existing PDF via qpdf's update-from-json.
     */
    protected function applyMetadata(string $file): void
    {
        $jsonFile = $file.'.json';

        $infoFields = [];
        foreach ($this->metadata as $key => $value) {
            // qpdf JSON v2 encodes PDF strings with a "u:" prefix.
            $infoFields["/{$key}"] = "u:{$value}";
        }

        $readCommand = [
            $this->collate->binaryPath(),
            '--json',
        ];

        // When the output has been encrypted, qpdf needs the owner password
        // to read the file back for the metadata update.
        if ($this->encryption) {
            $readCommand[] = "--password={$this->encryption['owner_password']}";
        }

        $readCommand[] = $file;

        $readResult = Process::run($readCommand);

        if (! $readResult->successful()) {
            throw new ProcessFailedException(
                "Collate: failed to read PDF for metadata update — {$readResult->errorOutput()}",
                $readResult->exitCode(),
                $readResult->errorOutput(),
            );
        }

        $existing = json_decode($readResult->output(), true);
        $qpdfObjects = $existing['qpdf'][1] ?? [];

        // The info ref is stored as e.g. "6 0 R" in the trailer value.
        // The corresponding object key is "obj:6 0 R".
        $infoRef = $qpdfObjects['trailer']['value']['/Info'] ?? null;

        $patch = [];

        if ($infoRef) {
            // Merge with existing values so that setting only e.g. Title
            // does not wipe Author, Producer, CreationDate, etc.
            $existingValues = $qpdfObjects["obj:{$infoRef}"]['value'] ?? [];
            $mergedValues = array_replace($existingValues, $infoFields);

            $patch["obj:{$infoRef}"] = ['value' => $mergedValues];
        } else {
            // No info object exists — create one and point the trailer at it.
            $maxId = 0;
            foreach (array_keys($qpdfObjects) as $key) {
                if (preg_match('/^obj:(\d+) \d+ R$/', $key, $matches)) {
                    $maxId = max($maxId, (int) $matches[1]);
                }
            }
            $newId = $maxId + 1;
            $newRef = "obj:{$newId} 0 R";
            $trailerRef = "{$newId} 0 R";

            $patch[$newRef] = ['value' => $infoFields];

            // Update only the /Info key on the trailer — qpdf merges the rest.
            $patch['trailer'] = ['value' => ['/Info' => $trailerRef]];
        }

        // The second element of the qpdf array is a flat object whose keys
        // are either "trailer" or "obj:n n R". No wrapper key is used.
        $json = json_encode([
            'qpdf' => [
                ['jsonversion' => 2, 'pushedinheritedpageresources' => false],
                $patch,
            ],
        ]);

        file_put_contents($jsonFile, $json);

        $updateCommand = [
            $this->collate->binaryPath(),
        ];

        if ($this->encryption) {
            $updateCommand[] = "--password={$this->encryption['owner_password']}";
        }

        $updateCommand[] = $file;
        $updateCommand[] = '--update-from-json='.$jsonFile;
        $updateCommand[] = '--replace-input';

        $result = Process::run($updateCommand);

        @unlink($jsonFile);

        if (! $result->successful()) {
            throw new ProcessFailedException(
                "Collate: failed to set metadata — {$result->errorOutput()}",
                $result->exitCode(),
                $result->errorOutput(),
            );
        }
    }

    /**
     * Build the qpdf command array.
     *
     * @return list<string>
     */
    protected function buildCommand(
        string $outputPath,
        ?string $pageOverride = null,
    ): array {
        $command = [$this->collate->binaryPath()];

        if ($this->decryptPassword !== null) {
            $command[] = "--password={$this->decryptPassword}";
            $command[] = '--decrypt';
        }

        // qpdf takes document-level data (outlines, bookmarks, tags) from the
        // primary input file. When a source is set, always use it as the
        // primary input so that document metadata is preserved. Inside --pages,
        // use "." to refer back to this primary input file.
        // When there is no source at all, --empty is the correct base.
        if ($this->source) {
            $command[] = $this->source;
        } else {
            $command[] = '--empty';
        }

        // The --pages block controls which pages end up in the output.
        // "." refers to the primary input file specified above.
        if ($this->source) {
            $pages = $pageOverride ?? $this->pageSelection;

            $command[] = '--pages';
            $command[] = '.';
            $command[] = $pages ?? '1-z';

            foreach ($this->additions as $addition) {
                $command[] = $addition['file'];
                $command[] = $addition['pages'] ?? '1-z';
            }

            $command[] = '--';
        } elseif (! empty($this->additions)) {
            $command[] = '--pages';

            foreach ($this->additions as $addition) {
                $command[] = $addition['file'];
                $command[] = $addition['pages'] ?? '1-z';
            }

            $command[] = '--';
        }

        // Rotations
        foreach ($this->rotations as $rotation) {
            $command[] = "--rotate=+{$rotation['degrees']}:{$rotation['pages']}";
        }

        // Encryption
        if ($this->encryption) {
            if ($this->encryption['bit_length'] < 256) {
                $command[] = '--allow-weak-crypto';
            }

            $command[] = '--encrypt';
            $command[] = $this->encryption['user_password'];
            $command[] = $this->encryption['owner_password'];
            $command[] = (string) $this->encryption['bit_length'];

            if (! empty($this->restrictions)) {
                foreach ($this->restrictions as $restriction) {
                    $command[] = self::RESTRICTIONS[$restriction];
                }
            }

            $command[] = '--';
        }

        // Overlay
        if ($this->overlayFile) {
            $command[] = '--overlay';
            $command[] = $this->overlayFile;
            $command[] = '--';
        }

        // Underlay
        if ($this->underlayFile) {
            $command[] = '--underlay';
            $command[] = $this->underlayFile;
            $command[] = '--';
        }

        // Flatten
        if ($this->flatten) {
            $command[] = '--flatten-annotations=all';
        }

        // Linearize
        if ($this->linearize) {
            $command[] = '--linearize';
        }

        $command[] = $outputPath;

        return $command;
    }

    /**
     * Resolve a file input to a local absolute path.
     *
     * For local disks, this returns the path directly. For remote disks (S3, etc.),
     * the file is downloaded to the temp directory so qpdf can access it.
     */
    protected function resolveFilePath(string|UploadedFile $file): string
    {
        if ($file instanceof UploadedFile) {
            $path = $file->getRealPath();

            if ($path === false) {
                throw new FileNotFoundException('Collate: the uploaded file is no longer available on disk.');
            }

            return $path;
        }

        $disk = Storage::disk($this->collate->diskName());

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return $disk->path($file);
        }

        return $this->downloadToTemp($disk, $file);
    }

    /**
     * Download a file from a remote disk to a local temp path.
     */
    protected function downloadToTemp(FilesystemAdapter $disk, string $file): string
    {
        $tempPath = $this->tempFilePath();

        // Use a stream rather than file_get_contents to avoid loading the
        // entire remote file into PHP memory before writing it to disk.
        $stream = $disk->readStream($file);

        if ($stream === null) {
            throw new \Illuminate\Contracts\Filesystem\FileNotFoundException(
                "Collate: could not read file '{$file}' from disk."
            );
        }

        file_put_contents($tempPath, $stream);

        $this->tempInputFiles[] = $tempPath;

        return $tempPath;
    }

    /**
     * Clean up any temporary input files downloaded from remote disks.
     */
    protected function cleanupTempInputFiles(): void
    {
        foreach ($this->tempInputFiles as $file) {
            @unlink($file);
        }

        $this->tempInputFiles = [];
    }

    /**
     * Generate a temporary file path.
     */
    protected function tempFilePath(): string
    {
        $dir = $this->collate->tempDirectory();

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.'/'.Str::uuid().'.pdf';
    }
}
