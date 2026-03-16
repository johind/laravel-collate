## Collate

Collate provides a fluent API for manipulating PDFs in Laravel, powered by qpdf. Use the `Johind\Collate\Facades\Collate` facade.

### Requirements

- PHP 8.4+, Laravel 11/12, qpdf v11.0.0+

### Entry Points

All return a `PendingCollate` fluent builder.

- `Collate::open($file)` — manipulate existing PDF (string path or `UploadedFile`).
- `Collate::inspect($file)` — read-only inspection (metadata, page count). Semantic alias for `open()`.
- `Collate::merge(...$files)` — combine multiple PDFs (strings, arrays, `UploadedFile`, or `Closure`).

### Disk Management

- `Collate::fromDisk('s3')` — set input disk (returns a new `Collate` instance).
- `->toDisk('local')` — set output disk on a `PendingCollate`.

### Page Operations

- `addPage($file, $pageNumber)` — add a single page from another PDF.
- `addPages($files, $range)` — add pages from file(s). `$range` cannot be used with an array of files.
- `removePage($pageNumber)` — remove a single page.
- `removePages($range)` — remove pages by array or range string.
- `onlyPages($range)` — keep only specified pages. **Mutually exclusive with `removePages()`.**
- `split($path)` — split into individual pages. Path must contain `{page}`. Returns `Collection<int, string>`.
- `rotate($degrees, $range)` — rotate pages. Degrees: 0, 90, 180, 270. `$range` defaults to `'1-z'`.

**Page range syntax (qpdf):** `1-5`, `1,3,5`, `1-3,7-9`, `z` (last page), `1-z` (all).

### Overlay / Underlay

- `overlay($file)` — layer on top (watermarks, stamps). Accepts string path or `UploadedFile`.
- `underlay($file)` — layer behind (backgrounds, letterheads). Accepts string path or `UploadedFile`.

### Encryption & Permissions

- `encrypt($userPassword, $ownerPassword, $bitLength)` — `$ownerPassword` defaults to `$userPassword`. `$bitLength` defaults to `256`. Valid: 40, 128, 256.
- `decrypt($password)` — decrypt a protected PDF.
- `restrict(...$permissions)` — **must be called after `encrypt()`.** Valid: `print`, `modify`, `extract`, `annotate`, `assemble`, `print-highres`, `form`, `modify-other`.

### Other Operations

- `flatten()` — flatten form fields/annotations.
- `linearize()` — optimize for web viewing.
- `withMetadata(title:, author:, subject:, keywords:, creator:, producer:, creationDate:, modDate:)` — set metadata. First argument also accepts a `PdfMetadata` instance (named params override its values).
- `metadata()` — returns `PdfMetadata` with: `title`, `author`, `subject`, `keywords`, `creator`, `producer`, `creationDate`, `modDate`.
- `pageCount()` — get page count.
- `dump()` — dump the built qpdf command and continue the chain. **Output may contain sensitive data.**
- `dd()` — dump the built qpdf command and stop execution. **Output may contain sensitive data.**

### Output

- `save($path)` — save to disk (returns `bool`).
- `download($filename)` — download response (`StreamedResponse`).
- `stream($filename)` — inline browser response (`StreamedResponse`).
- `content()` — raw binary string.
- Implements `Responsable` — return directly from controllers.

### Traits

- `Conditionable` — use `->when()` for conditional operations.
- `Macroable` — extend both `Collate` and `PendingCollate` with macros.

### Testing

`Collate::fake()` returns `CollateFake`. Assertions:

- `assertSaved($path, $callback)`, `assertNothingSaved()`
- `assertDownloaded($filename, $callback)`, `assertNothingDownloaded()`
- `assertStreamed($filename, $callback)`, `assertNothingStreamed()`
- `assertSplit()`

`PendingCollateFake` introspection for callbacks: `sourcePath()`, `selectedPages()`, `additions()`, `isEncrypted()`, `isLinearized()`, `isFlattened()`, `rotations()`, `outputDisk()`, `pageCount()` (returns 3 per file).

### Usage Examples

@verbatim
<code-snippet name="Open and manipulate a PDF" lang="php">
use Johind\Collate\Facades\Collate;

Collate::open('report.pdf')
    ->removePages('1')
    ->overlay('watermark.pdf')
    ->withMetadata(title: 'Q4 Report', author: 'Acme Corp')
    ->linearize()
    ->save('final-report.pdf');
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Merge multiple PDFs" lang="php">
use Johind\Collate\Facades\Collate;
use Johind\Collate\PendingCollate;

Collate::merge(function (PendingCollate $pdf) {
    $pdf->addPage('cover.pdf', 1);
    $pdf->addPages('chapters/intro.pdf');
    $pdf->addPages('chapters/main.pdf', range: '1-20');
})->save('book.pdf');
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Encrypt and restrict permissions" lang="php">
use Johind\Collate\Facades\Collate;

Collate::open('sensitive.pdf')
    ->encrypt(userPassword: 'user123', ownerPassword: 'owner456', bitLength: 256)
    ->restrict('print', 'extract', 'modify')
    ->save('protected.pdf');
</code-snippet>
@endverbatim

@verbatim
<code-snippet name="Testing with Collate::fake()" lang="php">
use Johind\Collate\Facades\Collate;
use Johind\Collate\PendingCollateFake;

Collate::fake();

// ... run your code ...

Collate::assertSaved('output.pdf', function (PendingCollateFake $pdf) {
    return $pdf->isEncrypted() && $pdf->isLinearized();
});
</code-snippet>
@endverbatim

### Configuration

Published via `php artisan vendor:publish --tag="collate-config"`:

- `binary_path` — path to qpdf binary (default: `'qpdf'`).
- `default_disk` — storage disk for file resolution.
- `temp_directory` — temp dir (default: `storage_path('app/collate')`).
