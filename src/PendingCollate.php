<?php

namespace Johind\Collate;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PendingCollate implements Responsable
{
    use Conditionable;

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
     * Whether to flatten form fields and annotations.
     */
    protected bool $flatten = false;

    /**
     * Metadata fields to set on the output PDF.
     *
     * @var array<string, string>
     */
    protected array $metadata = [];

    public function __construct(
        protected Collate $collate,
        string|UploadedFile|null $source = null,
    ) {
        if ($source !== null) {
            $this->source = $this->resolveFilePath($source);
        }
    }

    /**
     * Start manipulating an existing PDF.
     */
    public function open(string|UploadedFile $file): static
    {
        $this->source = $this->resolveFilePath($file);

        return $this;
    }

    /**
     * Add a complete file, or a single page from a file.
     */
    public function addPage(
        string|UploadedFile $file,
        ?int $pageNumber = null,
    ): static {
        $this->additions[] = [
            "file" => $this->resolveFilePath($file),
            "pages" => $pageNumber !== null ? (string) $pageNumber : null,
        ];

        return $this;
    }

    /**
     * Add a range of pages from a file, or multiple files at once.
     */
    public function addPages(
        string|array|UploadedFile $files,
        ?string $range = null,
    ): static {
        if (is_array($files)) {
            foreach ($files as $file) {
                $this->addPage($file);
            }

            return $this;
        }

        $this->additions[] = [
            "file" => $this->resolveFilePath($files),
            "pages" => $range,
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
     * Remove multiple pages or a range (e.g., [1, 3] or '5-10').
     */
    public function removePages(string|array $pages): static
    {
        if (is_array($pages)) {
            $pages = implode(",", $pages);
        }

        // qpdf doesn't have "remove" — we invert to a "keep everything except" selection.
        $this->pageSelection = "1-z,!{$pages}";

        return $this;
    }

    /**
     * Keep only the specified pages.
     */
    public function onlyPages(string|array $pages): static
    {
        if (is_array($pages)) {
            $pages = implode(",", $pages);
        }

        $this->pageSelection = $pages;

        return $this;
    }

    /**
     * Encrypt the document and restrict permissions.
     */
    public function encrypt(
        string $userPassword,
        ?string $ownerPassword = null,
        int $bitLength = 256,
    ): static {
        $this->encryption = [
            "user_password" => $userPassword,
            "owner_password" => $ownerPassword ?? $userPassword,
            "bit_length" => $bitLength,
        ];

        return $this;
    }

    /**
     * Set a password on the document.
     */
    public function password(string $password): static
    {
        return $this->encrypt($password);
    }

    /**
     * Prevent the document from being printed.
     */
    public function preventPrinting(): static
    {
        $this->restrictions[] = "print";

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
    public function rotate(int $degrees, string $pages = "1-z"): static
    {
        $this->rotations[] = [
            "degrees" => $degrees,
            "pages" => $pages,
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
        $result = Process::run([
            $this->collate->binaryPath(),
            "--json",
            "--json-key=objects",
            $this->source,
        ]);

        $json = json_decode($result->output(), true);
        $info = [];

        // qpdf JSON v2 nests document info under objects
        foreach ($json["objects"] ?? [] as $key => $object) {
            if (str_contains($key, "/Info")) {
                foreach ($object["value"] as $field => $meta) {
                    if (is_string($meta)) {
                        $info[$field] = $meta;
                    } elseif (is_array($meta) && isset($meta["value"])) {
                        $info[$field] = $meta["value"];
                    }
                }
            }
        }

        return PdfMetadata::fromArray($info);
    }

    /**
     * Set metadata on the output document.
     */
    public function setMetadata(
        ?string $title = null,
        ?string $author = null,
        ?string $subject = null,
        ?string $keywords = null,
    ): static {
        foreach (
            compact("title", "author", "subject", "keywords")
            as $key => $value
        ) {
            if ($value !== null) {
                $this->metadata[ucfirst($key)] = $value;
            }
        }

        return $this;
    }

    /**
     * Get the number of pages in the source document.
     */
    public function pageCount(): int
    {
        $result = Process::run([
            $this->collate->binaryPath(),
            "--show-npages",
            $this->source,
        ]);

        return (int) trim($result->output());
    }

    /**
     * Save the final PDF to a path on the configured disk.
     */
    public function save(string $path): bool
    {
        $tempOutput = $this->process();
        $disk = Storage::disk($this->collate->diskName());

        try {
            return $disk->put($path, file_get_contents($tempOutput));
        } finally {
            @unlink($tempOutput);
        }
    }

    /**
     * Return a download response to the browser.
     */
    public function download(
        string $filename = "document.pdf",
    ): StreamedResponse {
        return $this->toResponse(request(), $filename, "attachment");
    }

    /**
     * Stream the PDF inline to the browser.
     */
    public function stream(string $filename = "document.pdf"): StreamedResponse
    {
        return $this->toResponse(request(), $filename, "inline");
    }

    /**
     * Encode the PDF as a base64 string.
     */
    public function toBase64(): string
    {
        $tempOutput = $this->process();

        try {
            return base64_encode(file_get_contents($tempOutput));
        } finally {
            @unlink($tempOutput);
        }
    }

    /**
     * Split each page into a separate file, saved to the configured disk.
     *
     * The path may contain a {page} placeholder for the page number.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function split(
        string $path = "{page}.pdf",
    ): \Illuminate\Support\Collection {
        $dir = $this->collate->tempDirectory();

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $prefix = $dir . "/" . Str::uuid();
        $command = [$this->collate->binaryPath()];

        if ($this->source) {
            $command[] = $this->source;
        } else {
            $command[] = "--empty";
        }

        $command[] = "--split-pages";
        $command[] = "{$prefix}-%d.pdf";

        $result = Process::run($command);

        if (!$result->successful()) {
            throw new \RuntimeException(
                "Collate: qpdf split failed — {$result->errorOutput()}",
            );
        }

        $disk = Storage::disk($this->collate->diskName());
        $paths = collect();
        $page = 1;

        while (file_exists($tempFile = "{$prefix}-{$page}.pdf")) {
            $destination = str_replace("{page}", (string) $page, $path);
            $disk->put($destination, file_get_contents($tempFile));
            @unlink($tempFile);

            $paths->push($destination);
            $page++;
        }

        return $paths;
    }

    /**
     * Create an HTTP response for the given disposition type.
     */
    public function toResponse(
        $request,
        ?string $filename = null,
        string $disposition = "inline",
    ): StreamedResponse {
        $filename ??= "document.pdf";
        $tempOutput = $this->process();

        return response()->streamDownload(
            function () use ($tempOutput) {
                echo file_get_contents($tempOutput);
                @unlink($tempOutput);
            },
            $filename,
            [
                "Content-Type" => "application/pdf",
                "Content-Disposition" => "{$disposition}; filename=\"{$filename}\"",
            ],
        );
    }

    /**
     * Run the qpdf process and return the path to the temporary output file.
     */
    protected function process(?string $pageOverride = null): string
    {
        $tempOutput = $this->tempFilePath();
        $command = $this->buildCommand($tempOutput, $pageOverride);

        $result = Process::run($command);

        if (!$result->successful()) {
            @unlink($tempOutput);
            throw new \RuntimeException(
                "Collate: qpdf failed — {$result->errorOutput()}",
            );
        }

        if (!empty($this->metadata)) {
            $this->applyMetadata($tempOutput);
        }

        return $tempOutput;
    }

    /**
     * Apply metadata fields to an existing PDF via qpdf's update-from-json.
     */
    protected function applyMetadata(string $file): void
    {
        $jsonFile = $file . ".json";

        $infoFields = [];
        foreach ($this->metadata as $key => $value) {
            $infoFields["/{$key}"] = [
                "value" => "({$value})",
                "type" => "/string",
            ];
        }

        $json = json_encode([
            "qpdf" => [
                ["jsonversion" => 2, "pushedinheritedpageresources" => false],
                [
                    "objects" => [
                        "trailer.Info" => ["value" => $infoFields],
                    ],
                ],
            ],
        ]);

        file_put_contents($jsonFile, $json);

        $result = Process::run([
            $this->collate->binaryPath(),
            $file,
            "--update-from-json=" . $jsonFile,
            "--replace-input",
        ]);

        @unlink($jsonFile);

        if (!$result->successful()) {
            throw new \RuntimeException(
                "Collate: failed to set metadata — {$result->errorOutput()}",
            );
        }
    }

    /**
     * Run the qpdf process for a specific page selection.
     */
    protected function processForPages(string $pages): string
    {
        return $this->process($pages);
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

        if ($this->source && empty($this->additions)) {
            $command[] = $this->source;
        } elseif ($this->source) {
            $command[] = "--empty";
        } else {
            $command[] = "--empty";
        }

        // Page selection from source
        if ($this->source) {
            $pages = $pageOverride ?? $this->pageSelection;

            $command[] = "--pages";
            $command[] = $this->source;
            $command[] = $pages ?? "1-z";

            // Additional files
            foreach ($this->additions as $addition) {
                $command[] = $addition["file"];
                $command[] = $addition["pages"] ?? "1-z";
            }

            $command[] = "--";
        } elseif (!empty($this->additions)) {
            $command[] = "--pages";

            foreach ($this->additions as $addition) {
                $command[] = $addition["file"];
                $command[] = $addition["pages"] ?? "1-z";
            }

            $command[] = "--";
        }

        // Rotations
        foreach ($this->rotations as $rotation) {
            $command[] = "--rotate=+{$rotation["degrees"]}:{$rotation["pages"]}";
        }

        // Encryption
        if ($this->encryption) {
            $command[] = "--encrypt";
            $command[] = $this->encryption["user_password"];
            $command[] = $this->encryption["owner_password"];
            $command[] = (string) $this->encryption["bit_length"];

            foreach ($this->restrictions as $restriction) {
                $command[] = "--modify=none";
                $command[] = "--{$restriction}=n";
            }

            $command[] = "--";
        }

        // Overlay
        if ($this->overlayFile) {
            $command[] = "--overlay";
            $command[] = $this->overlayFile;
            $command[] = "--";
        }

        // Underlay
        if ($this->underlayFile) {
            $command[] = "--underlay";
            $command[] = $this->underlayFile;
            $command[] = "--";
        }

        // Flatten
        if ($this->flatten) {
            $command[] = "--flatten-annotations=all";
        }

        // Linearize
        if ($this->linearize) {
            $command[] = "--linearize";
        }

        $command[] = $outputPath;

        // Metadata is applied as a separate qpdf invocation after the main one,
        // so we handle it in the process() method instead.

        return $command;
    }

    /**
     * Resolve a file input to an absolute path.
     */
    protected function resolveFilePath(string|UploadedFile $file): string
    {
        if ($file instanceof UploadedFile) {
            return $file->getRealPath();
        }

        // If it's already an absolute path, use it directly.
        if (str_starts_with($file, "/")) {
            return $file;
        }

        // Otherwise, resolve from the configured disk.
        return Storage::disk($this->collate->diskName())->path($file);
    }

    /**
     * Generate a temporary file path.
     */
    protected function tempFilePath(): string
    {
        $dir = $this->collate->tempDirectory();

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . "/" . Str::uuid() . ".pdf";
    }
}
