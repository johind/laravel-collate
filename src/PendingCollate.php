<?php

declare(strict_types=1);

namespace Johind\Collate;

use BadMethodCallException;
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
use InvalidArgumentException;
use Johind\Collate\Exceptions\ProcessFailedException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PendingCollate implements Responsable
{
    use Conditionable;
    use Macroable;

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
     * The storage disk for the output file.
     */
    protected ?string $outputDisk = null;

    /**
     * Cache of page counts for individual files.
     *
     * @var array<string, int>
     */
    protected array $filePageCountCache = [];

    /**
     * Memoized total page count for the output document.
     */
    protected ?int $memoizedTotalPageCount = null;

    /**
     * Path to the processed PDF file.
     */
    protected ?string $processedPath = null;

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
        $this->cleanupProcessedFile();
    }

    /**
     * Set the storage disk for the output file.
     */
    public function toDisk(string $disk): static
    {
        $this->outputDisk = $disk;

        return $this;
    }

    /**
     * Add a single page from a file.
     */
    public function addPage(
        string|UploadedFile $file,
        int $pageNumber,
    ): static {
        $this->clearCache();

        return $this->addPages(
            $file,
            (string) $pageNumber,
        );
    }

    /**
     * Add a range of pages from a file, or multiple files at once.
     *
     * @param  string|array<int, string|UploadedFile>|UploadedFile  $files
     */
    public function addPages(
        string|array|UploadedFile $files,
        ?string $range = null,
    ): static {
        if (is_array($files) && $range !== null) {
            throw new InvalidArgumentException(
                'Cannot use range parameter when adding multiple files. '
                .'Chain multiple addPages() calls with range instead.'
            );
        }

        $this->clearCache();

        $files = is_array($files) ? $files : [$files];

        foreach ($files as $file) {
            $this->additions[] = [
                'file' => $this->resolveFilePath($file),
                'pages' => $range,
            ];
        }

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
     * @param  string|array<int, int|string>  $range
     */
    public function removePages(string|array $range): static
    {
        $source = $this->source ?? throw new BadMethodCallException(
            'Collate: cannot call removePages() when no source file is set. Use open() or inspect() first.'
        );

        if ($this->pageSelection !== null) {
            throw new BadMethodCallException(
                'Collate: cannot call removePages() after onlyPages() or removePages() has already been called.'
            );
        }

        $this->clearCache();

        $items = is_array($range) ? $range : explode(',', $range);
        $totalPageCount = $this->getFilePageCount($source);

        // Standardize input into removal boundaries: [ [start, end], [start, end], ... ]
        $intervals = [];

        foreach ($items as $item) {
            $item = mb_trim((string) $item);

            if ($item === '' || ! preg_match('/^(\d+|z)(-(\d+|z))?$/', $item)) {
                throw new InvalidArgumentException(
                    sprintf("Collate: '%s' is not a valid page number or range.", $item)
                );
            }

            if (str_contains($item, '-')) {
                [$startStr, $endStr] = explode('-', $item, 2);
                $start = ($startStr === 'z') ? $totalPageCount : (int) $startStr;
                $end = ($endStr === 'z') ? $totalPageCount : (int) $endStr;

                if ($start < 1 || $end < 1) {
                    throw new InvalidArgumentException(
                        sprintf('Collate: page numbers must be at least 1. Got range: %d-%d.', $start, $end)
                    );
                }

                // Normalise reverse ranges for the exclusion calculation
                $intervals[] = [min($start, $end), max($start, $end)];
            } else {
                $page = ($item === 'z') ? $totalPageCount : (int) $item;

                if ($page < 1) {
                    throw new InvalidArgumentException(
                        sprintf('Collate: page numbers must be at least 1. Got: %d.', $page)
                    );
                }

                $intervals[] = [$page, $page];
            }
        }

        // Merge overlapping intervals to simplify the keep-range calculation
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        if ($intervals !== []) {
            $current = $intervals[0];
            for ($i = 1, $count = count($intervals); $i < $count; $i++) {
                if ($intervals[$i][0] <= $current[1] + 1) {
                    $current[1] = max($current[1], $intervals[$i][1]);
                } else {
                    $merged[] = $current;
                    $current = $intervals[$i];
                }
            }

            $merged[] = $current;
        }

        $keepRanges = [];
        $lastEnd = 0;

        // Iterate through the merged "holes" (exclusions) and identify the "solid"
        // blocks of pages in between that we want to tell qpdf to KEEP.
        foreach ($merged as $interval) {
            if ($interval[0] > $totalPageCount) {
                continue;
            }

            if ($interval[0] > $lastEnd + 1) {
                $start = $lastEnd + 1;
                $end = $interval[0] - 1;

                $keepRanges[] = ($start === $end)
                    ? (string) $start
                    : sprintf('%d-%d', $start, $end);
            }

            $lastEnd = max($lastEnd, $interval[1]);
        }

        if ($lastEnd < $totalPageCount) {
            $start = $lastEnd + 1;
            $keepRanges[] = sprintf('%d-z', $start);
        }

        $this->pageSelection = implode(',', $keepRanges);

        return $this;
    }

    /**
     * Keep only the specified pages.
     *
     * @param  string|array<int, int|string>  $range
     */
    public function onlyPages(string|array $range): static
    {
        if ($this->source === null) {
            throw new BadMethodCallException(
                'Collate: cannot call onlyPages() when no source file is set. Use open() or inspect() first.'
            );
        }

        if ($this->pageSelection !== null) {
            throw new BadMethodCallException(
                'Collate: cannot call onlyPages() after removePages() or onlyPages() has already been called.'
            );
        }

        $this->clearCache();

        if (is_array($range)) {
            $range = implode(',', $range);
        }

        $this->pageSelection = $range;

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
            throw new InvalidArgumentException(
                'Encryption bit length must be 40, 128, or 256. Got: '.$bitLength,
            );
        }

        $this->clearCache();

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
        $this->clearCache();
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
            throw new BadMethodCallException(
                'Collate: cannot restrict permissions without encryption. Call encrypt() first.'
            );
        }

        foreach ($permissions as $permission) {
            if (! array_key_exists($permission, self::RESTRICTIONS)) {
                $valid = implode(', ', array_keys(self::RESTRICTIONS));
                throw new InvalidArgumentException(
                    sprintf("Collate: '%s' is not a valid permission. Valid permissions are: %s.", $permission, $valid)
                );
            }
        }

        $this->clearCache();

        array_push($this->restrictions, ...$permissions);
        $this->restrictions = array_values(array_unique($this->restrictions));

        return $this;
    }

    /**
     * Optimize the PDF for fast web viewing.
     */
    public function linearize(): static
    {
        $this->clearCache();
        $this->linearize = true;

        return $this;
    }

    /**
     * Rotate specific pages.
     */
    public function rotate(int $degrees, string $range = '1-z'): static
    {
        if (! in_array($degrees, [0, 90, 180, 270], true)) {
            throw new InvalidArgumentException(
                'Rotation degrees must be 0, 90, 180, or 270. Got: '.$degrees,
            );
        }

        $this->clearCache();

        $this->rotations[] = [
            'degrees' => $degrees,
            'pages' => $range,
        ];

        return $this;
    }

    /**
     * Overlay another PDF on top (watermarks, letterheads).
     */
    public function overlay(string|UploadedFile $file): static
    {
        $this->clearCache();
        $this->overlayFile = $this->resolveFilePath($file);

        return $this;
    }

    /**
     * Underlay another PDF behind the content (backgrounds).
     */
    public function underlay(string|UploadedFile $file): static
    {
        $this->clearCache();
        $this->underlayFile = $this->resolveFilePath($file);

        return $this;
    }

    /**
     * Flatten form fields and annotations.
     */
    public function flatten(): static
    {
        $this->clearCache();
        $this->flatten = true;

        return $this;
    }

    /**
     * Read the metadata from the source document.
     */
    public function metadata(): PdfMetadata
    {
        if ($this->source === null) {
            throw new BadMethodCallException('Collate: cannot read metadata without a source file. Use open() or inspect() first.');
        }

        $command = [
            $this->collate->binaryPath(),
            '--json',
        ];

        if ($this->decryptPassword !== null) {
            $command[] = '--password='.$this->decryptPassword;
        }

        $command[] = $this->source;

        $result = Process::run($command);

        if (! $result->successful()) {
            throw new ProcessFailedException(
                'Collate: failed to read PDF metadata — '.$result->errorOutput(),
                $result->exitCode() ?? 1,
                $result->errorOutput(),
            );
        }

        $json = json_decode($result->output(), true);

        if (! is_array($json) || ! is_array($json['qpdf'] ?? null) || ! is_array($json['qpdf'][1] ?? null)) {
            throw new RuntimeException('Collate: failed to parse qpdf JSON output — unexpected structure.');
        }

        /** @var array<string, mixed> $qpdfObjects */
        $qpdfObjects = $json['qpdf'][1];
        $info = [];

        // The info ref is stored as e.g. "6 0 R" in the trailer value.
        $trailerValue = is_array($qpdfObjects['trailer'] ?? null) && is_array($qpdfObjects['trailer']['value'] ?? null)
            ? $qpdfObjects['trailer']['value']
            : [];
        $infoRef = $trailerValue['/Info'] ?? null;

        // The object key is "obj:6 0 R" — we must prepend "obj:" to the ref.
        $infoObject = is_string($infoRef) ? ($qpdfObjects['obj:'.$infoRef] ?? null) : null;
        $infoValues = is_array($infoObject) && is_array($infoObject['value'] ?? null) ? $infoObject['value'] : null;

        if ($infoValues !== null) {
            foreach ($infoValues as $field => $meta) {
                // Strip the leading "/" from the field key to normalise
                // e.g. "/Title" → "Title" for PdfMetadata::fromArray.
                $key = mb_ltrim((string) $field, '/');

                // qpdf JSON v2 encodes strings with a "u:" prefix.
                if (is_string($meta) && str_starts_with($meta, 'u:')) {
                    $info[$key] = mb_substr($meta, 2);
                } elseif (is_string($meta)) {
                    $info[$key] = $meta;
                }
            }
        }

        return PdfMetadata::fromArray($info);
    }

    /**
     * Set metadata on the output document.
     *
     * Pass a PdfMetadata instance as the first argument to copy all its values.
     * Named parameters (author, subject, etc.) override the PdfMetadata values.
     * To override the title, pass it as a named string parameter in a separate call.
     */
    public function withMetadata(
        string|PdfMetadata|null $title = null,
        ?string $author = null,
        ?string $subject = null,
        ?string $keywords = null,
        ?string $creator = null,
        ?string $producer = null,
        ?string $creationDate = null,
        ?string $modDate = null,
    ): static {
        $meta = $title instanceof PdfMetadata ? $title->toArray() : [];

        $map = [
            'Title' => $title instanceof PdfMetadata ? ($meta['Title'] ?? null) : $title,
            'Author' => $author ?? ($meta['Author'] ?? null),
            'Subject' => $subject ?? ($meta['Subject'] ?? null),
            'Keywords' => $keywords ?? ($meta['Keywords'] ?? null),
            'Creator' => $creator ?? ($meta['Creator'] ?? null),
            'Producer' => $producer ?? ($meta['Producer'] ?? null),
            'CreationDate' => $creationDate ?? ($meta['CreationDate'] ?? null),
            'ModDate' => $modDate ?? ($meta['ModDate'] ?? null),
        ];

        $this->clearCache();

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
        if ($this->memoizedTotalPageCount !== null) {
            return $this->memoizedTotalPageCount;
        }

        $total = 0;

        if ($this->source !== null) {
            $count = $this->getFilePageCount($this->source);
            $total += $this->calculateSelectedPageCount($count, $this->pageSelection);
        }

        foreach ($this->additions as $addition) {
            $count = $this->getFilePageCount($addition['file']);
            $total += $this->calculateSelectedPageCount($count, $addition['pages']);
        }

        return $this->memoizedTotalPageCount = $total;
    }

    /**
     * Dump the built qpdf command without executing it.
     *
     * WARNING: The output may contain sensitive data such as file paths and passwords.
     */
    public function dump(): static
    {
        dump($this->buildCommand('{output}'));

        return $this;
    }

    /**
     * Dump the built qpdf command and end the script.
     *
     * WARNING: The output may contain sensitive data such as file paths and passwords.
     */
    public function dd(): never
    {
        dd($this->buildCommand('{output}'));
    }

    /**
     * Save the final PDF to a path on the configured disk.
     */
    public function save(string $path): bool
    {
        $tempOutput = $this->process();
        $disk = Storage::disk($this->outputDisk ?? $this->collate->diskName());

        try {
            // Use a stream rather than file_get_contents to avoid loading
            // the entire file into PHP memory, which would OOM on large PDFs.
            $stream = fopen($tempOutput, 'r');

            if ($stream === false) {
                throw new RuntimeException('Collate: failed to open temp file for reading: '.$tempOutput);
            }

            return (bool) $disk->put($path, $stream);
        } finally {
            // We don't unlink here if we are memoizing, cleanup happens in __destruct
            if ($this->processedPath === null) {
                @unlink($tempOutput);
            }
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
        $content = file_get_contents($tempOutput);

        if ($content === false) {
            throw new RuntimeException('Collate: failed to read temp file: '.$tempOutput);
        }

        return $content;
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
            $command[] = '--password='.$this->encryption['owner_password'];
        }

        $command[] = $processedFile;
        $command[] = '--split-pages';
        $command[] = $prefix.'-%d.pdf';

        $splitFiles = [];

        try {
            $result = Process::run($command);

            if (! $result->successful()) {
                throw new ProcessFailedException(
                    'Collate: qpdf split failed — '.$result->errorOutput(),
                    $result->exitCode() ?? 1,
                    $result->errorOutput(),
                );
            }

            $disk = Storage::disk($this->outputDisk ?? $this->collate->diskName());
            /** @var Collection<int, string> $paths */
            $paths = new Collection;

            // qpdf's %d produces zero-padded page numbers (e.g. "01", "02"),
            // so we use glob to discover the actual filenames instead of
            // guessing the format with an incrementing integer counter.
            $splitFiles = glob($prefix.'-*.pdf') ?: [];
            natsort($splitFiles);

            foreach ($splitFiles as $page => $tempFile) {
                $pageNumber = $page + 1;
                $destination = str_replace('{page}', (string) $pageNumber, $path);

                // Stream each page file to disk rather than loading it into
                // memory — individual pages of a large document can still be
                // several megabytes each.
                $stream = fopen($tempFile, 'r');

                if ($stream === false) {
                    throw new RuntimeException('Collate: failed to open split file for reading: '.$tempFile);
                }

                $disk->put($destination, $stream);

                $paths->push($destination);
            }

            return $paths;
        } finally {
            // Cleanup split files, but NOT the processed file if memoized
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
            function () use ($tempOutput): void {
                // Stream the file to the output buffer in chunks rather than
                // reading the whole PDF into a PHP string first.
                $stream = fopen($tempOutput, 'r');

                if ($stream === false) {
                    throw new RuntimeException('Collate: failed to open temp file for streaming: '.$tempOutput);
                }

                fpassthru($stream);
                fclose($stream);
            },
            $filename,
            ['Content-Type' => 'application/pdf'],
        );

        // streamDownload() always sets Content-Disposition to 'attachment'.
        // We override it here to support both inline and attachment dispositions.
        $response->headers->set(
            'Content-Disposition',
            sprintf('%s; filename="%s"', $disposition, $filename)
        );

        return $response;
    }

    /**
     * Get the page count of a specific file.
     */
    protected function getFilePageCount(string $file): int
    {
        if (isset($this->filePageCountCache[$file])) {
            return $this->filePageCountCache[$file];
        }

        $command = [
            $this->collate->binaryPath(),
            '--show-npages',
        ];

        if ($this->decryptPassword !== null) {
            $command[] = '--password='.$this->decryptPassword;
        }

        $command[] = $file;

        $result = Process::run($command);

        if (! $result->successful()) {
            throw new ProcessFailedException(
                sprintf("Collate: failed to count pages for file '%s' — %s", $file, $result->errorOutput()),
                $result->exitCode() ?? 1,
                $result->errorOutput(),
            );
        }

        return $this->filePageCountCache[$file] = (int) mb_trim($result->output());
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
            $item = mb_trim($item);

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
     * Run the qpdf process and return the path to the temporary output file.
     */
    protected function process(?string $pageOverride = null): string
    {
        if ($pageOverride === null && $this->processedPath !== null && file_exists($this->processedPath)) {
            return $this->processedPath;
        }

        $tempOutput = $this->tempFilePath();
        $command = $this->buildCommand($tempOutput, $pageOverride);

        try {
            $result = Process::run($command);

            if (! $result->successful()) {
                @unlink($tempOutput);
                throw new ProcessFailedException(
                    'Collate: qpdf failed — '.$result->errorOutput(),
                    $result->exitCode() ?? 1,
                    $result->errorOutput(),
                );
            }

            if ($this->metadata !== []) {
                try {
                    $this->applyMetadata($tempOutput);
                } catch (Throwable $e) {
                    @unlink($tempOutput);
                    throw $e;
                }
            }

            if ($pageOverride === null) {
                $this->processedPath = $tempOutput;
            }

            return $tempOutput;
        } finally {
            // Input files are cleaned up in __destruct
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
            $infoFields['/'.$key] = 'u:'.$value;
        }

        $readCommand = [
            $this->collate->binaryPath(),
            '--json',
        ];

        // When the output has been encrypted, qpdf needs the owner password
        // to read the file back for the metadata update.
        if ($this->encryption) {
            $readCommand[] = '--password='.$this->encryption['owner_password'];
        }

        $readCommand[] = $file;

        $readResult = Process::run($readCommand);

        if (! $readResult->successful()) {
            throw new ProcessFailedException(
                'Collate: failed to read PDF for metadata update — '.$readResult->errorOutput(),
                $readResult->exitCode() ?? 1,
                $readResult->errorOutput(),
            );
        }

        $existing = json_decode($readResult->output(), true);

        if (! is_array($existing) || ! is_array($existing['qpdf'] ?? null) || ! is_array($existing['qpdf'][1] ?? null)) {
            throw new RuntimeException('Collate: failed to parse qpdf JSON output for metadata update — unexpected structure.');
        }

        /** @var array<string, mixed> $qpdfObjects */
        $qpdfObjects = $existing['qpdf'][1];

        // The info ref is stored as e.g. "6 0 R" in the trailer value.
        // The corresponding object key is "obj:6 0 R".
        $trailerValue = is_array($qpdfObjects['trailer'] ?? null) && is_array($qpdfObjects['trailer']['value'] ?? null)
            ? $qpdfObjects['trailer']['value']
            : [];
        $infoRef = $trailerValue['/Info'] ?? null;

        $patch = [];

        if (is_string($infoRef)) {
            // Merge with existing values so that setting only e.g. Title
            // does not wipe Author, Producer, CreationDate, etc.
            $infoObject = $qpdfObjects['obj:'.$infoRef] ?? null;
            $existingValues = is_array($infoObject) && is_array($infoObject['value'] ?? null)
                ? $infoObject['value']
                : [];
            $mergedValues = array_replace($existingValues, $infoFields);

            $patch['obj:'.$infoRef] = ['value' => $mergedValues];
        } else {
            // No info object exists — create one and point the trailer at it.
            $maxId = 0;
            foreach (array_keys($qpdfObjects) as $key) {
                if (preg_match('/^obj:(\d+) \d+ R$/', (string) $key, $matches)) {
                    $maxId = max($maxId, (int) $matches[1]);
                }
            }

            $newId = $maxId + 1;
            $newRef = sprintf('obj:%d 0 R', $newId);
            $trailerRef = $newId.' 0 R';

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
            $updateCommand[] = '--password='.$this->encryption['owner_password'];
        }

        $updateCommand[] = $file;
        $updateCommand[] = '--update-from-json='.$jsonFile;
        $updateCommand[] = '--replace-input';

        $result = Process::run($updateCommand);

        @unlink($jsonFile);

        if (! $result->successful()) {
            throw new ProcessFailedException(
                'Collate: failed to set metadata — '.$result->errorOutput(),
                $result->exitCode() ?? 1,
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
            $command[] = '--password='.$this->decryptPassword;
            $command[] = '--decrypt';
        }

        // qpdf takes document-level data (outlines, bookmarks, tags) from the
        // primary input file. When a source is set, always use it as the
        // primary input so that document metadata is preserved. Inside --pages,
        // use "." to refer back to this primary input file.
        // When there is no source at all, --empty is the correct base.
        $command[] = $this->source ?: '--empty';

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
        } elseif ($this->additions !== []) {
            $command[] = '--pages';

            foreach ($this->additions as $addition) {
                $command[] = $addition['file'];
                $command[] = $addition['pages'] ?? '1-z';
            }

            $command[] = '--';
        }

        // Rotations
        foreach ($this->rotations as $rotation) {
            $command[] = sprintf('--rotate=+%d:%s', $rotation['degrees'], $rotation['pages']);
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

            foreach ($this->restrictions as $restriction) {
                $command[] = self::RESTRICTIONS[$restriction];
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
            throw new FileNotFoundException(
                sprintf("Collate: could not read file '%s' from disk.", $file)
            );
        }

        file_put_contents($tempPath, $stream);

        $this->tempInputFiles[] = $tempPath;

        return $tempPath;
    }

    /**
     * Clear memoized page counts and processed paths.
     */
    protected function clearCache(): void
    {
        $this->memoizedTotalPageCount = null;
        $this->cleanupProcessedFile();
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
     * Clean up the memoized processed file.
     */
    protected function cleanupProcessedFile(): void
    {
        if ($this->processedPath !== null) {
            @unlink($this->processedPath);
            $this->processedPath = null;
        }
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
