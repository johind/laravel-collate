<?php

declare(strict_types=1);

namespace Johind\Collate;

use BadMethodCallException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Johind\Collate\Exceptions\CollateException;
use Johind\Collate\Exceptions\ProcessFailedException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\WhitespacePathNormalizer;
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
     * Placeholder shown instead of passwords in dump() and dd() output.
     */
    protected const string REDACTED = '********';

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
     * Whether to strip all metadata from the output.
     */
    protected bool $stripMetadata = false;

    /**
     * Whether to apply optimization flags.
     */
    protected bool $optimize = false;

    /**
     * Whether metadata has been explicitly configured on the output.
     */
    protected bool $hasSetMetadata = false;

    /**
     * Memoized total page count for the output document.
     */
    protected ?int $memoizedTotalPageCount = null;

    /**
     * Memoized qpdf JSON output for the source file.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $qpdfJsonCache = null;

    /**
     * Cache of whether a file contains document-level named destinations.
     *
     * @var array<string, bool>
     */
    protected array $namedDestinationCache = [];

    /**
     * Addition index to use as qpdf's primary document input for catalog data.
     */
    protected ?int $documentDataPrimaryAddition = null;

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

        if ($range !== null) {
            $this->validatePageRange($range, false);
        }

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
        $source = $this->requireSource('removePages');

        if ($this->pageSelection !== null) {
            throw new BadMethodCallException(
                'Collate: cannot call removePages() after onlyPages() or removePages() has already been called.'
            );
        }

        $this->clearCache();

        $rangeString = is_array($range) ? implode(',', $range) : $range;
        $rangeString = mb_trim($rangeString);
        $this->validatePageRange($rangeString, false);

        if (preg_match('/:(odd|even)$/i', $rangeString)) {
            $totalPages = $this->getFilePageCount($source);
            $pagesToRemove = $this->expandPageSelection($rangeString, $totalPages);
            $pagesToRemove = array_values(array_unique($pagesToRemove));
            sort($pagesToRemove);

            if ($pagesToRemove === []) {
                $this->pageSelection = '1-z';

                return $this;
            }

            $exclusions = array_map(
                static fn (int $page): string => 'x'.$page,
                $pagesToRemove
            );
        } else {
            $items = explode(',', $rangeString);
            $exclusions = [];

            foreach ($items as $item) {
                $exclusions[] = 'x'.mb_trim($item);
            }
        }

        $this->pageSelection = '1-z,'.implode(',', $exclusions);

        return $this;
    }

    /**
     * Keep only the specified pages.
     *
     * @param  string|array<int, int|string>  $range
     */
    public function onlyPages(string|array $range): static
    {
        $this->requireSource('onlyPages');

        if ($this->pageSelection !== null) {
            throw new BadMethodCallException(
                'Collate: cannot call onlyPages() after removePages() or onlyPages() has already been called.'
            );
        }

        $this->clearCache();

        if (is_array($range)) {
            $range = implode(',', $range);
        }

        $this->validatePageRange($range, false);

        $this->pageSelection = $range;

        return $this;
    }

    /**
     * Encrypt the document and restrict permissions.
     *
     * When no owner password is given, a random one is generated so that the
     * user password cannot be used to lift restrictions set via restrict().
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

        $this->ensureValidPassword($userPassword);

        if ($ownerPassword !== null) {
            $this->ensureValidPassword($ownerPassword);
        }

        $this->clearCache();

        $this->encryption = [
            'user_password' => $userPassword,
            'owner_password' => $ownerPassword ?? bin2hex(random_bytes(16)),
            'bit_length' => $bitLength,
        ];

        return $this;
    }

    /**
     * Decrypt a password-protected document.
     */
    public function decrypt(string $password): static
    {
        $this->ensureValidPassword($password);
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

        $range = mb_trim($range);
        $this->validatePageRange($range, true);

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
        $json = $this->getQpdfJson('metadata');
        $info = [];

        /** @var array<int, mixed> $qpdf */
        $qpdf = $json['qpdf'];
        /** @var array<string, mixed> $qpdfObjects */
        $qpdfObjects = $qpdf[1];

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

                if (is_string($meta)) {
                    $info[$key] = $this->decodeJsonString($meta);
                }
            }
        }

        return PdfMetadata::fromArray($info);
    }

    /**
     * Check if the source document is encrypted.
     */
    public function isEncrypted(): bool
    {
        return $this->inspectExitCode('isEncrypted', '--is-encrypted');
    }

    /**
     * Check if the source document requires a password to open.
     */
    public function hasPassword(): bool
    {
        return $this->inspectExitCode('hasPassword', '--requires-password');
    }

    /**
     * Check if the source document is linearized (optimized for fast web viewing).
     */
    public function isLinearized(): bool
    {
        $json = $this->getQpdfJson('isLinearized');

        /** @var array<int, mixed> $qpdf */
        $qpdf = $json['qpdf'];
        /** @var array<string, mixed> $qpdfObjects */
        $qpdfObjects = $qpdf[1];

        foreach ($qpdfObjects as $object) {
            $value = is_array($object) && is_array($object['value'] ?? null)
                ? $object['value']
                : null;

            if (is_array($value) && array_key_exists('/Linearized', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the PDF version string (e.g. "1.7", "2.0").
     */
    public function pdfVersion(): string
    {
        $json = $this->getQpdfJson('pdfVersion');

        /** @var array<int, mixed> $qpdf */
        $qpdf = $json['qpdf'];
        $version = is_array($qpdf[0] ?? null) ? ($qpdf[0]['pdfversion'] ?? null) : null;

        if (! is_string($version)) {
            throw new CollateException('Collate: failed to read PDF version from qpdf JSON output.');
        }

        return $version;
    }

    /**
     * Return the underlying page-box dimensions in PDF points.
     *
     * The returned size is based on the page's /MediaBox, including inherited
     * page-tree values and /UserUnit scaling. It is not adjusted for /Rotate.
     *
     * @throws InvalidArgumentException When the requested page does not exist.
     * @throws CollateException When qpdf JSON does not contain a valid /MediaBox.
     */
    public function pageSize(int $page = 1): PageSize
    {
        $json = $this->getQpdfJson('pageSize');

        /** @var list<array{pageposfrom1: int, object: string}> $pages */
        $pages = $json['pages'] ?? [];

        $pageInfo = null;
        foreach ($pages as $p) {
            if ($p['pageposfrom1'] === $page) {
                $pageInfo = $p;
                break;
            }
        }

        if ($pageInfo === null) {
            throw new InvalidArgumentException(
                sprintf('Collate: page %d does not exist in the document (document has %d pages).', $page, count($pages))
            );
        }

        /** @var array<int, mixed> $qpdf */
        $qpdf = $json['qpdf'];
        /** @var array<string, mixed> $qpdfObjects */
        $qpdfObjects = $qpdf[1];
        $pageObject = $qpdfObjects['obj:'.$pageInfo['object']] ?? null;
        /** @var array<string, mixed> $pageValues */
        $pageValues = is_array($pageObject) && is_array($pageObject['value'] ?? null)
            ? $pageObject['value']
            : [];

        /** @var list<float|int>|null $mediaBox */
        $mediaBox = $this->pageTreeValue($qpdfObjects, $pageValues, '/MediaBox');

        if (! is_array($mediaBox) || count($mediaBox) < 4) {
            throw new CollateException(
                sprintf('Collate: page %d does not have a valid /MediaBox.', $page)
            );
        }

        $userUnitRaw = $this->pageTreeValue($qpdfObjects, $pageValues, '/UserUnit');
        $userUnit = is_numeric($userUnitRaw)
            ? (float) $userUnitRaw
            : 1.0;

        return new PageSize(
            width: ((float) $mediaBox[2] - (float) $mediaBox[0]) * $userUnit,
            height: ((float) $mediaBox[3] - (float) $mediaBox[1]) * $userUnit,
            userUnit: $userUnit,
        );
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
        if ($this->stripMetadata) {
            throw new BadMethodCallException(
                'Collate: cannot call withMetadata() after withoutMetadata() has already been called.'
            );
        }

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

        $this->clearProcessedFile();
        $this->hasSetMetadata = true;

        foreach ($map as $key => $value) {
            if ($value !== null) {
                $this->metadata[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Strip all metadata fields from the output document.
     */
    public function withoutMetadata(): static
    {
        if ($this->hasSetMetadata) {
            throw new BadMethodCallException(
                'Collate: cannot call withoutMetadata() after withMetadata() has already been called.'
            );
        }

        $this->clearProcessedFile();
        $this->stripMetadata = true;

        return $this;
    }

    /**
     * Apply optimization to reduce file size.
     */
    public function optimize(): static
    {
        $this->clearProcessedFile();
        $this->optimize = true;

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
     * Passwords are redacted. The output may still contain file paths.
     */
    public function dump(): static
    {
        dump($this->redactCommand($this->buildCommand('{output}')));

        return $this;
    }

    /**
     * Dump the built qpdf command and end the script.
     *
     * Passwords are redacted. The output may still contain file paths.
     */
    public function dd(): never
    {
        dd($this->redactCommand($this->buildCommand('{output}')));
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
                throw new CollateException('Collate: failed to open temp file for reading: '.$tempOutput);
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
            throw new CollateException('Collate: failed to read temp file: '.$tempOutput);
        }

        return $content;
    }

    /**
     * Split each page into a separate file, saved to the configured disk.
     *
     * The path may contain a {page} placeholder for the page number. Without
     * one, the page number is appended before the file extension
     * (e.g. "pages/doc.pdf" becomes "pages/doc-1.pdf", "pages/doc-2.pdf", …).
     *
     * @return Collection<int, string>
     */
    public function split(string $path): Collection
    {
        // Run the full pipeline first so that all operations (page selection,
        // rotations, encryption, overlays, etc.) are applied before splitting.
        // The processed file becomes the input to the split command.
        $processedFile = $this->process();

        // qpdf creates the page files itself with default permissions, so
        // they are written into a directory only the current user can open.
        $splitDirectory = $this->privateTempDirectory();
        $prefix = $splitDirectory.'/page';
        $command = [$this->collate->binaryPath()];

        if ($this->encryption) {
            $command[] = '--password='.$this->encryption['owner_password'];
        }

        $command[] = $processedFile;
        $command[] = '--split-pages';

        // qpdf does not carry the input's encryption over to split pages, so
        // the encryption settings must be applied to each page explicitly.
        array_push($command, ...$this->encryptionArguments());

        $command[] = $prefix.'-%d.pdf';

        $splitFiles = [];

        try {
            $result = $this->runQpdf($command);

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
                $destination = $this->splitDestination($path, $page + 1);

                // Stream each page file to disk rather than loading it into
                // memory — individual pages of a large document can still be
                // several megabytes each.
                $stream = fopen($tempFile, 'r');

                if ($stream === false) {
                    throw new CollateException('Collate: failed to open split file for reading: '.$tempFile);
                }

                try {
                    $written = $disk->put($destination, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                if (! $written) {
                    throw new CollateException(sprintf("Collate: failed to write split page to '%s'.", $destination));
                }

                $paths->push($destination);
            }

            return $paths;
        } finally {
            // Cleanup split files, but NOT the processed file if memoized
            foreach (glob($splitDirectory.'/*') ?: [] as $tempFile) {
                @unlink($tempFile);
            }

            @rmdir($splitDirectory);
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
        $filename = self::sanitizeFilename($filename ?? 'document.pdf');
        self::ensureValidDisposition($disposition);

        $tempOutput = $this->process();

        // The closure intentionally binds $this: it keeps this instance (and
        // therefore the memoized temp file) alive until the response is sent.
        // Laravel encodes the Content-Disposition header, including an ASCII
        // fallback and an RFC 6266 filename* parameter for non-ASCII names.
        return response()->streamDownload(
            function () use ($tempOutput): void {
                // Stream the file to the output buffer in chunks rather than
                // reading the whole PDF into a PHP string first.
                $stream = fopen($tempOutput, 'r');

                if ($stream === false) {
                    throw new CollateException('Collate: failed to open temp file for streaming: '.$tempOutput);
                }

                fpassthru($stream);
                fclose($stream);
            },
            $filename,
            ['Content-Type' => 'application/pdf'],
            $disposition,
        );
    }

    /**
     * Remove control characters that cannot appear in an HTTP header value.
     */
    protected static function sanitizeFilename(string $filename): string
    {
        // No /u modifier: the pattern is ASCII-only and must not reject
        // filenames that are not valid UTF-8.
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? '';

        return $sanitized === '' ? 'document.pdf' : $sanitized;
    }

    /**
     * Ensure the Content-Disposition type is one browsers understand.
     */
    protected static function ensureValidDisposition(string $disposition): void
    {
        if (! in_array($disposition, ['inline', 'attachment'], true)) {
            throw new InvalidArgumentException(
                sprintf("Collate: disposition must be 'inline' or 'attachment'. Got: '%s'.", $disposition)
            );
        }
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

        $result = $this->runQpdf($command);

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
     *
     * This evaluates qpdf page-range syntax including exclusions (`x` prefix)
     * and positional `:odd`/`:even` modifiers. The modifiers select by position
     * within the resulting page sequence, not by page number parity.
     */
    protected function calculateSelectedPageCount(int $totalInFile, ?string $selection): int
    {
        if ($selection === null || $selection === '1-z') {
            return $totalInFile;
        }

        $this->validatePageRange($selection, true);
        $pages = $this->expandPageSelection($selection, $totalInFile);

        return count($pages);
    }

    /**
     * Expand a qpdf page-range expression into an ordered list of page numbers.
     *
     * Supports ranges (`1-5`), reverse ranges (`5-1`), `z` (last page),
     * `rN` (Nth page from the end), exclusions (`x3`, `x5-8`), and a trailing positional `:odd`/`:even`
     * modifier that applies to the whole expression, as in qpdf.
     *
     * @return list<int>
     */
    protected function expandPageSelection(string $selection, int $totalPages): array
    {
        [$selection, $globalModifier] = $this->extractGlobalModifier($selection);

        $items = explode(',', $selection);
        $pages = [];

        foreach ($items as $item) {
            $item = mb_trim($item);

            if ($item === '') {
                continue;
            }

            // Check for exclusion prefix
            $isExclusion = str_starts_with($item, 'x');
            if ($isExclusion) {
                $item = mb_substr($item, 1);
            }

            $expanded = $this->expandRangeToken($item, $totalPages);

            if ($isExclusion) {
                $pages = array_values(array_diff($pages, $expanded));
            } else {
                array_push($pages, ...$expanded);
            }
        }

        // Apply global positional modifier
        if ($globalModifier !== null) {
            return $this->applyPositionalModifier($pages, $globalModifier);
        }

        return $pages;
    }

    /**
     * Validate a page-range string for use in page selection.
     *
     * Ensures each comma-separated token matches qpdf page-range grammar:
     * a page number, `z` or `rN`, optionally followed by `-` and another one.
     * A single `:odd` or `:even` modifier may follow the whole expression;
     * qpdf rejects modifiers on individual items. Exclusions (`x`) are only
     * allowed where qpdf accepts them as part of a selection.
     *
     * @throws InvalidArgumentException
     */
    protected function validatePageRange(string $range, bool $allowExclusions): void
    {
        // Strip a single trailing global :odd/:even modifier before splitting.
        [$normalized] = $this->extractGlobalModifier($range);

        $items = explode(',', $normalized);

        foreach ($items as $item) {
            $item = mb_trim($item);

            if ($item === '') {
                throw new InvalidArgumentException(
                    sprintf("Collate: '%s' is not a valid page range — it contains empty segments.", $range)
                );
            }

            $isExclusion = str_starts_with($item, 'x');
            if ($isExclusion) {
                if (! $allowExclusions) {
                    throw new InvalidArgumentException(
                        sprintf("Collate: '%s' is not a valid page number or range.", $item)
                    );
                }

                $item = mb_substr($item, 1);
                if ($item === '') {
                    throw new InvalidArgumentException(
                        sprintf("Collate: '%s' is not a valid page number or range.", $range)
                    );
                }
            }

            if (preg_match('/^(\d+|z|r\d+)(?:-(\d+|z|r\d+))?:(odd|even)$/', $item)) {
                throw new InvalidArgumentException(
                    sprintf("Collate: '%s' is not a valid page range — an :odd or :even modifier may only appear once, at the end of the whole range.", $range)
                );
            }

            // qpdf's range syntax is case-sensitive: "z", "r3", ":odd".
            if (! preg_match('/^(\d+|z|r\d+)(?:-(\d+|z|r\d+))?$/', $item, $matches)) {
                throw new InvalidArgumentException(
                    sprintf("Collate: '%s' is not a valid page number or range.", $item)
                );
            }

            foreach (array_slice($matches, 1) as $bound) {
                if ($bound !== 'z' && (int) mb_ltrim($bound, 'r') < 1) {
                    throw new InvalidArgumentException(
                        sprintf("Collate: page numbers must be at least 1. Got: '%s'.", $item)
                    );
                }
            }
        }
    }

    /**
     * Extract a trailing global :odd/:even modifier if present.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function extractGlobalModifier(string $range): array
    {
        if (preg_match('/(\d|z):(odd|even)$/', $range, $globalMatch)) {
            $lastComma = mb_strrpos($range, ',');
            $lastItem = $lastComma !== false ? mb_substr($range, $lastComma + 1) : $range;

            if (! str_starts_with(mb_trim($lastItem), 'x')) {
                $modifier = $globalMatch[2];
                $normalized = mb_substr($range, 0, -mb_strlen(':'.$globalMatch[2]));

                return [$normalized, $modifier];
            }
        }

        return [$range, null];
    }

    /**
     * Expand a single range token (e.g. "1", "3-8", "z", "z-1", "r2-r1") into page numbers.
     *
     * @return list<int>
     */
    protected function expandRangeToken(string $token, int $totalPages): array
    {
        if (str_contains($token, '-')) {
            [$startStr, $endStr] = explode('-', $token, 2);
            $start = $this->resolvePageNumber($startStr, $totalPages);
            $end = $this->resolvePageNumber($endStr, $totalPages);

            // Clamp to the document before iterating so that huge page numbers
            // (e.g. "1-99999999999") cannot turn into a long-running loop.
            $low = max(1, min($start, $end));
            $high = min($totalPages, max($start, $end));

            if ($low > $high) {
                return [];
            }

            $pages = range($low, $high);

            return $start <= $end ? $pages : array_reverse($pages);
        }

        $page = $this->resolvePageNumber($token, $totalPages);

        return ($page >= 1 && $page <= $totalPages) ? [$page] : [];
    }

    /**
     * Resolve a page reference: a number, "z" (last page) or "rN" (Nth page from the end).
     */
    protected function resolvePageNumber(string $reference, int $totalPages): int
    {
        return match (true) {
            $reference === 'z' => $totalPages,
            str_starts_with($reference, 'r') => $totalPages - (int) mb_substr($reference, 1) + 1,
            default => (int) $reference,
        };
    }

    /**
     * Filter a page list by positional parity (1st, 3rd, 5th… = odd; 2nd, 4th, 6th… = even).
     *
     * @param  list<int>  $pages
     * @return list<int>
     */
    protected function applyPositionalModifier(array $pages, string $modifier): array
    {
        $offset = $modifier === 'odd' ? 0 : 1;

        return array_values(
            array_filter($pages, fn (int $index): bool => $index % 2 === $offset, ARRAY_FILTER_USE_KEY)
        );
    }

    /**
     * Run the qpdf process and return the path to the temporary output file.
     */
    protected function process(): string
    {
        if ($this->processedPath !== null && file_exists($this->processedPath)) {
            return $this->processedPath;
        }

        $tempOutput = $this->tempFilePath();
        $this->selectDocumentDataPrimary();
        $command = $this->buildCommand($tempOutput);

        // Input files are cleaned up in __destruct.
        try {
            $result = $this->runQpdf($command);

            if (! $result->successful()) {
                throw new ProcessFailedException(
                    'Collate: qpdf failed — '.$result->errorOutput(),
                    $result->exitCode() ?? 1,
                    $result->errorOutput(),
                );
            }

            if ($this->metadata !== []) {
                $this->applyMetadata($tempOutput);
            }

            if ($this->stripMetadata) {
                $this->removeMetadata($tempOutput);
            }
        } catch (Throwable $throwable) {
            @unlink($tempOutput);

            throw $throwable;
        }

        return $this->processedPath = $tempOutput;
    }

    /**
     * Apply metadata fields to an existing PDF via qpdf's update-from-json.
     */
    protected function applyMetadata(string $file): void
    {
        $infoFields = [];
        foreach ($this->metadata as $key => $value) {
            // qpdf JSON v2 encodes PDF strings with a "u:" prefix.
            $infoFields['/'.$key] = 'u:'.$value;
        }

        ['qpdfObjects' => $qpdfObjects, 'trailerValue' => $trailerValue] = $this->readQpdfObjectsForUpdate(
            $file,
            'metadata update',
        );

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

            // Include the full trailer so qpdf can locate /Root when the
            // info dictionary was previously stripped from the file.
            $trailerValue['/Info'] = $trailerRef;
            $patch['trailer'] = ['value' => $trailerValue];
        }

        $this->updatePdfFromJsonPatch($file, $patch, 'set metadata');
    }

    /**
     * Remove all metadata fields from a PDF via qpdf's update-from-json.
     */
    protected function removeMetadata(string $file): void
    {
        ['qpdfObjects' => $qpdfObjects, 'trailerValue' => $trailerValue] = $this->readQpdfObjectsForUpdate(
            $file,
            'metadata removal',
        );

        $patch = [];
        $infoRef = $trailerValue['/Info'] ?? null;

        if (is_string($infoRef)) {
            unset($trailerValue['/Info']);
            $patch['trailer'] = ['value' => $trailerValue];
        }

        foreach ($qpdfObjects as $objectKey => $object) {
            if ($objectKey === 'trailer') {
                continue;
            }

            // Stream objects expose metadata inside stream dictionaries, not
            // scalar value entries, so non-dictionary values can be skipped.
            $value = is_array($object) && is_array($object['value'] ?? null)
                ? $object['value']
                : null;
            if ($value === null) {
                continue;
            }

            if (! array_key_exists('/Metadata', $value)) {
                continue;
            }

            unset($value['/Metadata']);
            $patch[$objectKey] = ['value' => $value];
        }

        if ($patch === []) {
            return;
        }

        $this->updatePdfFromJsonPatch($file, $patch, 'remove metadata');
    }

    /**
     * Read qpdf objects and trailer values from an existing PDF for in-place updates.
     *
     * @return array{qpdfObjects: array<string, mixed>, trailerValue: array<string, mixed>}
     */
    protected function readQpdfObjectsForUpdate(string $file, string $action): array
    {
        // When the output has been encrypted, qpdf needs the owner password
        // to read the file back for subsequent JSON-based updates.
        $existing = $this->readQpdfJsonFile($file, $action, $this->encryption['owner_password'] ?? null);

        /** @var array<int, mixed> $qpdf */
        $qpdf = $existing['qpdf'];
        /** @var array<string, mixed> $qpdfObjects */
        $qpdfObjects = $qpdf[1];

        $trailerValue = is_array($qpdfObjects['trailer'] ?? null) && is_array($qpdfObjects['trailer']['value'] ?? null)
            ? $qpdfObjects['trailer']['value']
            : [];

        /** @var array{qpdfObjects: array<string, mixed>, trailerValue: array<string, mixed>} $result */
        $result = [
            'qpdfObjects' => $qpdfObjects,
            'trailerValue' => $trailerValue,
        ];

        return $result;
    }

    /**
     * Apply a qpdf JSON patch to an existing PDF in place.
     *
     * @param  array<string, mixed>  $patch
     */
    protected function updatePdfFromJsonPatch(string $file, array $patch, string $action): void
    {
        $json = json_encode([
            'qpdf' => [
                ['jsonversion' => 2, 'pushedinheritedpageresources' => false],
                $patch,
            ],
        ]);

        if (! is_string($json)) {
            throw new CollateException(sprintf('Collate: failed to encode qpdf JSON patch for %s.', $action));
        }

        $jsonFile = $this->tempFilePath('json');

        // Write to a separate private file and move it into place instead of
        // using --replace-input, which would recreate the file with default
        // (world-readable) permissions.
        $output = null;

        try {
            if (file_put_contents($jsonFile, $json) === false) {
                throw new CollateException(sprintf('Collate: failed to write temporary JSON patch for %s.', $action));
            }

            $output = $this->tempFilePath();
            $updateCommand = [$this->collate->binaryPath()];

            if ($this->encryption) {
                $updateCommand[] = '--password='.$this->encryption['owner_password'];
            }

            $updateCommand[] = $file;
            $updateCommand[] = '--update-from-json='.$jsonFile;
            $updateCommand[] = $output;

            $result = $this->runQpdf($updateCommand);

            if (! $result->successful()) {
                throw new ProcessFailedException(
                    sprintf('Collate: failed to %s — %s', $action, $result->errorOutput()),
                    $result->exitCode() ?? 1,
                    $result->errorOutput(),
                );
            }

            if (! @rename($output, $file)) {
                throw new CollateException(sprintf('Collate: failed to replace the document after %s.', $action));
            }

            $output = null;
        } finally {
            @unlink($jsonFile);

            if ($output !== null) {
                @unlink($output);
            }
        }
    }

    /**
     * Get an inheritable page-tree value from a page or its ancestor pages nodes.
     *
     * Returns the raw value as decoded from qpdf JSON. Callers are responsible
     * for validating and normalizing the shape and scalar types they expect.
     *
     * @param  array<string, mixed>  $qpdfObjects
     * @param  array<string, mixed>  $pageValues
     */
    protected function pageTreeValue(array $qpdfObjects, array $pageValues, string $key): mixed
    {
        if (array_key_exists($key, $pageValues)) {
            return $pageValues[$key];
        }

        $visited = [];
        $parentRef = $pageValues['/Parent'] ?? null;

        while (is_string($parentRef) && ! isset($visited[$parentRef])) {
            $visited[$parentRef] = true;

            $parentObject = $qpdfObjects['obj:'.$parentRef] ?? null;
            $parentValues = is_array($parentObject) && is_array($parentObject['value'] ?? null)
                ? $parentObject['value']
                : [];

            if (array_key_exists($key, $parentValues)) {
                return $parentValues[$key];
            }

            $parentRef = $parentValues['/Parent'] ?? null;
        }

        return null;
    }

    /**
     * Run a qpdf exit-code inspection command (exit 0 = true, exit 2 = false).
     */
    protected function inspectExitCode(string $method, string $flag): bool
    {
        $source = $this->requireSource($method);

        $result = $this->runQpdf([
            $this->collate->binaryPath(),
            $flag,
            $source,
        ]);

        return match ($result->exitCode()) {
            0 => true,
            2 => false,
            default => throw new ProcessFailedException(
                sprintf('Collate: failed to inspect PDF — %s', $result->errorOutput()),
                $result->exitCode() ?? 1,
                $result->errorOutput(),
            ),
        };
    }

    /**
     * Get the full qpdf JSON output for the source file, memoized.
     *
     * @return array<string, mixed>
     */
    protected function getQpdfJson(string $caller = 'inspect'): array
    {
        $source = $this->requireSource($caller);

        if ($this->qpdfJsonCache !== null) {
            return $this->qpdfJsonCache;
        }

        $json = $this->readQpdfJsonFile($source, 'read PDF', $this->decryptPassword);

        /** @var array<string, mixed> $json */
        return $this->qpdfJsonCache = $json;
    }

    /**
     * Prefer an input that carries named destinations as qpdf's primary input.
     *
     * Link annotations live on pages and are copied by --pages, but internal
     * GoTo actions generated from HTML anchors often point at names stored in
     * the catalog's /Names /Dests tree. qpdf preserves that document-level
     * tree only from the primary input file, so using --empty or a plain cover
     * page as the primary input can leave the clickable annotations pointing
     * at destinations that no longer exist.
     */
    protected function selectDocumentDataPrimary(): void
    {
        $this->documentDataPrimaryAddition = null;

        if ($this->additions === []) {
            return;
        }

        if ($this->source !== null && $this->decryptPassword !== null) {
            return;
        }

        if ($this->source !== null && $this->hasNamedDestinations($this->source)) {
            return;
        }

        foreach ($this->additions as $index => $addition) {
            if ($this->hasNamedDestinations($addition['file'])) {
                $this->documentDataPrimaryAddition = $index;

                return;
            }
        }
    }

    /**
     * Determine whether a PDF catalog contains a named destination tree.
     */
    protected function hasNamedDestinations(string $file): bool
    {
        if (array_key_exists($file, $this->namedDestinationCache)) {
            return $this->namedDestinationCache[$file];
        }

        $password = $this->decryptPassword !== null && $file === $this->source
            ? $this->decryptPassword
            : null;
        $json = $this->readQpdfJsonFile($file, 'named destination inspection', $password);

        /** @var array<int, mixed> $qpdf */
        $qpdf = $json['qpdf'];
        /** @var array<string, mixed> $qpdfObjects */
        $qpdfObjects = $qpdf[1];

        $trailerValue = is_array($qpdfObjects['trailer'] ?? null) && is_array($qpdfObjects['trailer']['value'] ?? null)
            ? $qpdfObjects['trailer']['value']
            : [];
        $rootRef = $trailerValue['/Root'] ?? null;
        $catalogObject = is_string($rootRef) ? ($qpdfObjects['obj:'.$rootRef] ?? null) : null;
        /** @var array<string, mixed> $catalogValue */
        $catalogValue = is_array($catalogObject) && is_array($catalogObject['value'] ?? null)
            ? $catalogObject['value']
            : [];

        return $this->namedDestinationCache[$file] = $this->catalogHasNamedDestinations($qpdfObjects, $catalogValue);
    }

    /**
     * Read qpdf JSON for any PDF file.
     *
     * @return array<string, mixed>
     */
    protected function readQpdfJsonFile(string $file, string $action, ?string $password = null): array
    {
        $command = [
            $this->collate->binaryPath(),
            '--json=2',
        ];

        if ($password !== null) {
            $command[] = '--password='.$password;
        }

        $command[] = $file;

        $result = $this->runQpdf($command);

        if (! $result->successful()) {
            throw new ProcessFailedException(
                sprintf('Collate: failed to read PDF for %s — %s', $action, $result->errorOutput()),
                $result->exitCode() ?? 1,
                $result->errorOutput(),
            );
        }

        $json = json_decode($result->output(), true);

        if (! is_array($json) || ! is_array($json['qpdf'] ?? null) || ! is_array($json['qpdf'][1] ?? null)) {
            throw new CollateException(
                sprintf('Collate: failed to parse qpdf JSON output for %s — unexpected structure.', $action)
            );
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    /**
     * Check a catalog dictionary for /Dests or /Names /Dests.
     *
     * @param  array<string, mixed>  $qpdfObjects
     * @param  array<string, mixed>  $catalogValue
     */
    protected function catalogHasNamedDestinations(array $qpdfObjects, array $catalogValue): bool
    {
        if (array_key_exists('/Dests', $catalogValue)) {
            return true;
        }

        $names = $catalogValue['/Names'] ?? null;
        if (is_string($names)) {
            $namesObject = $qpdfObjects['obj:'.$names] ?? null;
            $names = is_array($namesObject) && is_array($namesObject['value'] ?? null)
                ? $namesObject['value']
                : null;
        }

        return is_array($names) && array_key_exists('/Dests', $names);
    }

    /**
     * Ensure a source file is set, or throw.
     */
    protected function requireSource(string $method): string
    {
        return $this->source ?? throw new BadMethodCallException(
            sprintf('Collate: cannot call %s() when no source file is set. Use open() or inspect() first.', $method)
        );
    }

    /**
     * Build the qpdf command array.
     *
     * @return list<string>
     */
    protected function buildCommand(string $outputPath): array
    {
        $command = [$this->collate->binaryPath()];

        if ($this->decryptPassword !== null) {
            $command[] = '--password='.$this->decryptPassword;
            $command[] = '--decrypt';
        }

        // qpdf takes document-level data (names, outlines, bookmarks, tags)
        // from the primary input file. When a merged addition has named
        // destinations and the source/empty input does not, use that addition
        // as the primary input while preserving the requested page order below.
        $primaryInput = $this->documentDataPrimaryAddition !== null
            ? $this->additions[$this->documentDataPrimaryAddition]['file']
            : ($this->source ?: '--empty');

        $command[] = $primaryInput;

        // The --pages block controls which pages end up in the output.
        // "." refers to the primary input file specified above.
        if ($this->source !== null) {
            $command[] = '--pages';
            $command[] = $this->documentDataPrimaryAddition === null ? '.' : $this->source;
            $command[] = $this->pageSelection ?? '1-z';

            foreach ($this->additions as $index => $addition) {
                $command[] = $this->documentDataPrimaryAddition === $index ? '.' : $addition['file'];
                $command[] = $addition['pages'] ?? '1-z';
            }

            $command[] = '--';
        } elseif ($this->additions !== []) {
            $command[] = '--pages';

            foreach ($this->additions as $index => $addition) {
                $command[] = $this->documentDataPrimaryAddition === $index ? '.' : $addition['file'];
                $command[] = $addition['pages'] ?? '1-z';
            }

            $command[] = '--';
        }

        // Rotations
        foreach ($this->rotations as $rotation) {
            $command[] = sprintf('--rotate=+%d:%s', $rotation['degrees'], $rotation['pages']);
        }

        // Encryption
        array_push($command, ...$this->encryptionArguments());

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

        // Optimize
        if ($this->optimize) {
            if (! $this->linearize) {
                $command[] = '--object-streams=generate';
            }

            $command[] = '--remove-unreferenced-resources=yes';
            $command[] = '--recompress-flate';
            $command[] = '--compression-level=9';
        }

        // Linearize
        if ($this->linearize) {
            $command[] = '--linearize';
        }

        $command[] = $outputPath;

        return $command;
    }

    /**
     * Build the qpdf --encrypt arguments for the configured encryption.
     *
     * @return list<string>
     */
    protected function encryptionArguments(): array
    {
        if ($this->encryption === null) {
            return [];
        }

        $arguments = [];

        if ($this->encryption['bit_length'] < 256) {
            $arguments[] = '--allow-weak-crypto';
        }

        // The named forms (qpdf 11+) accept any password, including ones that
        // start with "--", which the positional form would parse as options.
        $arguments[] = '--encrypt';
        $arguments[] = '--user-password='.$this->encryption['user_password'];
        $arguments[] = '--owner-password='.$this->encryption['owner_password'];
        $arguments[] = '--bits='.$this->encryption['bit_length'];

        foreach ($this->restrictions as $restriction) {
            $arguments[] = self::RESTRICTIONS[$restriction];
        }

        $arguments[] = '--';

        return $arguments;
    }

    /**
     * Run a qpdf command with the configured timeout, keeping passwords out
     * of the process arguments and out of timeout exception messages.
     *
     * Passwords would otherwise be visible to other users on the host (e.g.
     * via `ps`) and end up in logs when Laravel reports a timed-out process
     * with its full command line.
     *
     * @param  list<string>  $command
     */
    protected function runQpdf(array $command): ProcessResult
    {
        $secretFiles = [];

        try {
            $command = $this->moveSecretsToFiles($command, $secretFiles);

            $timeout = $this->collate->timeout();
            $process = $timeout > 0 ? Process::timeout($timeout) : Process::forever();

            return $process->run($command);
        } catch (ProcessTimedOutException $processTimedOutException) {
            $errorOutput = $processTimedOutException->result->errorOutput();

            throw new ProcessFailedException(
                sprintf('Collate: qpdf timed out after %d seconds.', $this->collate->timeout()),
                $processTimedOutException->result->exitCode() ?? 1,
                $errorOutput,
            );
        } finally {
            foreach ($secretFiles as $secretFile) {
                @unlink($secretFile);
            }
        }
    }

    /**
     * Replace passwords in a qpdf command with references to private temp files.
     *
     * `--password=…` becomes `--password-file=…`, and `--user-password=…` /
     * `--owner-password=…` are read from qpdf argument files (`@file`).
     *
     * @param  list<string>  $command
     * @param  list<string>  $secretFiles
     * @return list<string>
     */
    protected function moveSecretsToFiles(array $command, array &$secretFiles): array
    {
        return array_map(function (string $argument) use (&$secretFiles): string {
            if (str_starts_with($argument, '--password=')) {
                $secretFiles[] = $file = $this->writeSecretFile(mb_substr($argument, mb_strlen('--password='), null, '8bit'));

                return '--password-file='.$file;
            }

            if (str_starts_with($argument, '--user-password=') || str_starts_with($argument, '--owner-password=')) {
                $secretFiles[] = $file = $this->writeSecretFile($argument);

                return '@'.$file;
            }

            return $argument;
        }, $command);
    }

    /**
     * Write a secret to a temp file that only the current user can read.
     *
     * The ".json" extension is not about the contents: AppArmor's qpdf profile
     * (enforced on Ubuntu 26.04) only lets qpdf open .pdf, .qdf and .json
     * files, and qpdf silently treats an unreadable @file as a literal argument.
     */
    protected function writeSecretFile(string $contents): string
    {
        $file = $this->tempFilePath('json', 'secret-');

        if (file_put_contents($file, $contents."\n") === false) {
            @unlink($file);

            throw new CollateException('Collate: failed to write a temporary password file.');
        }

        return $file;
    }

    /**
     * Replace passwords in a qpdf command with a placeholder for display.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    protected function redactCommand(array $command): array
    {
        return array_map(static function (string $argument): string {
            foreach (['--password=', '--user-password=', '--owner-password='] as $option) {
                if (str_starts_with($argument, $option)) {
                    return $option.self::REDACTED;
                }
            }

            return $argument;
        }, $command);
    }

    /**
     * Ensure a password can be passed to qpdf safely.
     *
     * qpdf reads passwords from files line by line, so line breaks and NUL
     * bytes cannot be represented.
     */
    protected function ensureValidPassword(string $password): void
    {
        if (preg_match('/[\r\n\x00]/', $password)) {
            throw new InvalidArgumentException('Collate: passwords must not contain line breaks or NUL bytes.');
        }
    }

    /**
     * Decode a qpdf JSON v2 string value.
     *
     * qpdf prefixes Unicode strings with "u:" and binary strings with "b:"
     * followed by hex. qpdf already decodes UTF-16 strings itself, so "b:"
     * is used for 8-bit strings whose bytes qpdf would not round-trip. These
     * are kept as-is when they are valid UTF-8 (which many PDF producers
     * write), and otherwise decoded as PDFDocEncoding, as the PDF spec says.
     */
    protected function decodeJsonString(string $value): string
    {
        if (str_starts_with($value, 'u:')) {
            return mb_substr($value, 2);
        }

        $hex = mb_substr($value, 2);

        if (! str_starts_with($value, 'b:') || ! preg_match('/^(?:[0-9a-f]{2})*$/i', $hex)) {
            return $value;
        }

        $bytes = (string) hex2bin($hex);

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = mb_substr($bytes, 3, null, '8bit');
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return $this->decodePdfDocEncoding($bytes);
    }

    /**
     * Decode a PDFDocEncoding string to UTF-8.
     *
     * PDFDocEncoding matches Latin-1 except for the bytes mapped below
     * (PDF 2.0, Annex D). Undefined bytes become U+FFFD.
     */
    protected function decodePdfDocEncoding(string $bytes): string
    {
        $map = [
            0x18 => 0x02D8, 0x19 => 0x02C7, 0x1A => 0x02C6, 0x1B => 0x02D9,
            0x1C => 0x02DD, 0x1D => 0x02DB, 0x1E => 0x02DA, 0x1F => 0x02DC,
            0x7F => 0xFFFD, 0x80 => 0x2022, 0x81 => 0x2020, 0x82 => 0x2021,
            0x83 => 0x2026, 0x84 => 0x2014, 0x85 => 0x2013, 0x86 => 0x0192,
            0x87 => 0x2044, 0x88 => 0x2039, 0x89 => 0x203A, 0x8A => 0x2212,
            0x8B => 0x2030, 0x8C => 0x201E, 0x8D => 0x201C, 0x8E => 0x201D,
            0x8F => 0x2018, 0x90 => 0x2019, 0x91 => 0x201A, 0x92 => 0x2122,
            0x93 => 0xFB01, 0x94 => 0xFB02, 0x95 => 0x0141, 0x96 => 0x0152,
            0x97 => 0x0160, 0x98 => 0x0178, 0x99 => 0x017D, 0x9A => 0x0131,
            0x9B => 0x0142, 0x9C => 0x0153, 0x9D => 0x0161, 0x9E => 0x017E,
            0x9F => 0xFFFD, 0xA0 => 0x20AC, 0xAD => 0xFFFD,
        ];

        $decoded = '';

        foreach (mb_str_split($bytes, 1, '8bit') as $byte) {
            $decoded .= mb_chr($map[ord($byte)] ?? ord($byte), 'UTF-8');
        }

        return $decoded;
    }

    /**
     * Resolve the destination path for a single split page.
     */
    protected function splitDestination(string $path, int $page): string
    {
        if (str_contains($path, '{page}')) {
            return str_replace('{page}', (string) $page, $path);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.'.$extension;
        $base = $suffix === '' ? $path : mb_substr($path, 0, -mb_strlen($suffix));

        return $base.'-'.$page.$suffix;
    }

    /**
     * Resolve a file input to a local absolute path.
     *
     * For local disks, this returns the path directly. For remote disks (S3, etc.),
     * the file is downloaded to the temp directory so qpdf can access it.
     *
     * Paths are normalized the same way Laravel's Storage facade does, so
     * paths that escape the disk root (e.g. "../.env") are rejected.
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

        $file = new WhitespacePathNormalizer()->normalizePath($file);
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
        // Use a stream rather than file_get_contents to avoid loading the
        // entire remote file into PHP memory before writing it to disk.
        $stream = $disk->readStream($file);

        if ($stream === null) {
            throw new FileNotFoundException(
                sprintf("Collate: could not read file '%s' from disk.", $file)
            );
        }

        try {
            $tempPath = $this->tempFilePath();
            $written = file_put_contents($tempPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            @unlink($tempPath);

            throw new CollateException(
                sprintf("Collate: failed to copy file '%s' from disk to a temporary file.", $file)
            );
        }

        $this->tempInputFiles[] = $tempPath;

        return $tempPath;
    }

    /**
     * Clear memoized page counts and processed paths.
     */
    protected function clearCache(): void
    {
        $this->memoizedTotalPageCount = null;
        $this->qpdfJsonCache = null;
        $this->documentDataPrimaryAddition = null;
        $this->clearProcessedFile();
    }

    /**
     * Clear only the memoized processed output path.
     */
    protected function clearProcessedFile(): void
    {
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
     * Create an empty temporary file that only the current user can read.
     *
     * qpdf keeps the permissions of an existing output file, so creating the
     * file up front means documents are never written world-readable, even
     * when the temp directory already existed with broader permissions.
     *
     * Every file qpdf reads must end in .pdf, .qdf or .json, because
     * AppArmor's qpdf profile (enforced on Ubuntu 26.04) denies anything else.
     */
    protected function tempFilePath(string $extension = 'pdf', string $prefix = ''): string
    {
        $path = $this->ensureTempDirectory().'/'.$prefix.Str::uuid().'.'.$extension;

        if (! @touch($path) || ! @chmod($path, 0600)) {
            @unlink($path);

            throw new CollateException(sprintf("Collate: failed to create temporary file '%s'.", $path));
        }

        return $path;
    }

    /**
     * Create a temporary directory that only the current user can access.
     */
    protected function privateTempDirectory(): string
    {
        $dir = $this->ensureTempDirectory().'/'.Str::uuid();

        if (! @mkdir($dir, 0700)) {
            throw new CollateException(sprintf("Collate: failed to create temporary directory '%s'.", $dir));
        }

        return $dir;
    }

    /**
     * Create the temp directory if needed, readable only by the current user,
     * since it may hold decrypted documents. Collate does not change the
     * permissions of an existing directory; the files it writes there are
     * private either way.
     */
    protected function ensureTempDirectory(): string
    {
        $dir = $this->collate->tempDirectory();

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new CollateException(sprintf("Collate: failed to create temp directory '%s'.", $dir));
        }

        return $dir;
    }
}
