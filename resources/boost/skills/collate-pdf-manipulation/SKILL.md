---
name: collate-pdf-manipulation
description: "Manipulate PDFs with the Collate package: merge, split, extract, rotate, encrypt, watermark, and flatten. Use when working with PDF files in Laravel, including reading metadata, combining documents, or preparing PDFs for AI ingestion."
---

# Collate PDF Manipulation

Collate provides a fluent API for manipulating PDFs in Laravel, powered by the `qpdf` binary. Use the `Johind\Collate\Facades\Collate` facade as the main entry point.

## Requirements

- PHP 8.4+, Laravel 11 or 12
- `qpdf` v11.0.0+ must be installed on the system

## Entry Points

There are three entry points — choose based on intent:

```php
use Johind\Collate\Facades\Collate;

// Manipulate an existing PDF
Collate::open('path/to/file.pdf');

// Read-only inspection (metadata, page count) — semantic alias for open()
Collate::inspect('path/to/file.pdf');

// Combine multiple PDFs into one
Collate::merge('file1.pdf', 'file2.pdf', 'file3.pdf');
```

All three return a `PendingCollate` fluent builder. `open()` and `inspect()` also accept `UploadedFile` instances.

## Disk Management

Files resolve from the configured default disk. Switch disks on the fly:

```php
// Read from S3, save to local
Collate::fromDisk('s3')->open('input.pdf')->toDisk('local')->save('output.pdf');
```

## Page Operations

### Adding Pages

```php
Collate::open('report.pdf')
    ->addPage('appendix.pdf', pageNumber: 3)    // Single page
    ->addPages('terms.pdf')                      // Entire file
    ->addPages('appendix.pdf', range: '1-5')     // Page range
    ->addPages(['a.pdf', 'b.pdf'])               // Multiple files
    ->save('output.pdf');
```

**Important:** The `range` parameter cannot be used with an array of files. Chain multiple `addPages()` calls instead.

### Removing Pages

```php
Collate::open('doc.pdf')
    ->removePage(3)                   // Single page
    ->save('output.pdf');

Collate::open('doc.pdf')
    ->removePages([1, 3, 5])         // Multiple pages
    ->save('output.pdf');

Collate::open('doc.pdf')
    ->removePages('5-10')            // Range
    ->save('output.pdf');
```

### Extracting Pages

```php
Collate::open('doc.pdf')
    ->onlyPages([1, 2, 3])           // Keep only these pages
    ->save('output.pdf');

Collate::open('doc.pdf')
    ->onlyPages('1-5,8,11-z')        // qpdf range syntax
    ->save('output.pdf');
```

**Constraint:** `onlyPages()` and `removePages()` are mutually exclusive — calling both throws `BadMethodCallException`.

### Page Range Syntax

Anywhere a range string is accepted (`onlyPages()`, `addPages()`, `removePages()`, `rotate()`), use qpdf range syntax:

| Expression | Meaning              |
|------------|----------------------|
| `1-5`      | Pages 1 through 5   |
| `1,3,5`    | Pages 1, 3, and 5   |
| `1-3,7-9`  | Pages 1–3 and 7–9   |
| `z`        | Last page            |
| `1-z`      | All pages            |

### Splitting

Split every page into its own file. Always include `{page}` in the path:

```php
$paths = Collate::open('multi-page.pdf')
    ->split('pages/page-{page}.pdf');
// Returns Collection ['pages/page-1.pdf', 'pages/page-2.pdf', ...]
```

Operations are applied before splitting, so chain freely.

### Rotating

```php
Collate::open('scanned.pdf')
    ->rotate(90)                      // All pages
    ->save('rotated.pdf');

Collate::open('scanned.pdf')
    ->rotate(90, range: '1-3')        // Specific pages
    ->rotate(180, range: '5')
    ->save('fixed.pdf');
```

Valid degrees: 0, 90, 180, 270.

## Merging PDFs

```php
// Simple merge
Collate::merge('cover.pdf', 'ch1.pdf', 'ch2.pdf')->save('book.pdf');

// Array of files
Collate::merge(['doc1.pdf', 'doc2.pdf'])->save('merged.pdf');

// Closure for fine-grained control
Collate::merge(function (PendingCollate $pdf) {
    $pdf->addPage('cover.pdf', 1);
    $pdf->addPages('appendix.pdf', range: '1-3');
})->save('book.pdf');
```

## Overlays and Underlays

```php
// Overlay (on top) — watermarks, stamps
Collate::open('doc.pdf')->overlay('watermark.pdf')->save('watermarked.pdf');

// Underlay (behind) — backgrounds, letterheads
Collate::open('content.pdf')->underlay('letterhead.pdf')->save('branded.pdf');
```

## Encryption, Decryption, and Permissions

```php
// Encrypt with a password
Collate::open('doc.pdf')->encrypt('secret')->save('protected.pdf');

// Full control with separate passwords and restrictions
Collate::open('doc.pdf')
    ->encrypt(userPassword: 'user-pw', ownerPassword: 'owner-pw', bitLength: 256)
    ->restrict('print', 'extract', 'modify')
    ->save('locked.pdf');

// Decrypt
Collate::open('locked.pdf')->decrypt('secret')->save('unlocked.pdf');

// Re-encrypt
Collate::open('locked.pdf')
    ->decrypt('old-password')
    ->encrypt('new-password')
    ->save('re-encrypted.pdf');
```

**Constraint:** `restrict()` must be called after `encrypt()` — calling it without encryption throws `BadMethodCallException`.

Valid permissions for `restrict()`: `print`, `modify`, `extract`, `annotate`, `assemble`, `print-highres`, `form`, `modify-other`.

## Flattening and Linearization

```php
// Flatten form fields and annotations into page content
Collate::open('form.pdf')->flatten()->save('flattened.pdf');

// Optimize for fast web viewing
Collate::open('report.pdf')->linearize()->save('web-optimized.pdf');
```

## Metadata

### Reading

```php
$meta = Collate::inspect('doc.pdf')->metadata();
$meta->title;         // string|null
$meta->author;        // string|null
$meta->subject;
$meta->keywords;
$meta->creator;
$meta->producer;
$meta->creationDate;
$meta->modDate;
```

### Writing

```php
Collate::open('doc.pdf')
    ->withMetadata(title: 'Report', author: 'Jane Doe')
    ->save('output.pdf');

// Copy metadata from another document
$meta = Collate::inspect('source.pdf')->metadata();
Collate::open('target.pdf')->withMetadata($meta)->save('output.pdf');
```

## Page Count

```php
$count = Collate::inspect('doc.pdf')->pageCount();

// Also available mid-chain
Collate::merge('a.pdf', 'b.pdf')
    ->when(fn ($pdf) => $pdf->pageCount() > 10, fn ($pdf) => $pdf->rotate(90))
    ->save('merged.pdf');
```

## Output Options

```php
// Save to disk
Collate::open('input.pdf')->save('output.pdf');

// Download response
return Collate::open('invoice.pdf')->download('invoice.pdf');

// Stream inline in browser
return Collate::open('invoice.pdf')->stream('invoice.pdf');

// Raw binary content
$content = Collate::open('doc.pdf')->content();

// Return directly from controller (implements Responsable)
public function show()
{
    return Collate::open('invoice.pdf');
}
```

## Conditional Operations

`PendingCollate` uses the `Conditionable` trait:

```php
Collate::open('doc.pdf')
    ->when($request->boolean('watermark'), fn ($pdf) => $pdf->overlay('watermark.pdf'))
    ->when($request->boolean('flatten'), fn ($pdf) => $pdf->flatten())
    ->save('output.pdf');
```

## Extending with Macros

Both `Collate` and `PendingCollate` use `Macroable`:

```php
// Add chainable operations to PendingCollate
PendingCollate::macro('stamp', function () {
    return $this->overlay('assets/stamp.pdf');
});

// Add entry points to Collate
Collate::macro('openInvoice', function (int $id) {
    return $this->open("invoices/{$id}.pdf");
});
```

## Testing with Fakes

Use `Collate::fake()` to prevent real PDF processing in tests:

```php
use Johind\Collate\Facades\Collate;

Collate::fake();

// Run your code...

// Assert operations occurred
Collate::assertSaved('reports/final.pdf');
Collate::assertSaved(callback: fn ($pending) => $pending->isEncrypted());
Collate::assertNothingSaved();

Collate::assertDownloaded('invoice.pdf');
Collate::assertNothingDownloaded();

Collate::assertStreamed('preview.pdf');
Collate::assertNothingStreamed();

Collate::assertSplit();
```

The fake's `PendingCollateFake` exposes introspection methods for callback assertions:

- `sourcePath()` — the source file path
- `selectedPages()` — the page selection string
- `additions()` — array of added files and page ranges
- `isEncrypted()`, `isLinearized()`, `isFlattened()` — boolean flags
- `rotations()` — list of rotation operations applied
- `outputDisk()` — the output disk name
- `pageCount()` — returns 3 pages per file in the fake

## Configuration

Published via `php artisan vendor:publish --tag="collate-config"`:

```php
return [
    'binary_path' => env('COLLATE_BINARY_PATH', 'qpdf'),
    'default_disk' => env('COLLATE_DISK'),
    'temp_directory' => env('COLLATE_TEMP_DIR', storage_path('app/collate')),
];
```

## Common Pitfalls

- Always include `{page}` in `split()` paths — without it, pages overwrite each other.
- `onlyPages()` and `removePages()` cannot be combined on the same instance.
- `restrict()` requires `encrypt()` to be called first.
- `range` parameter in `addPages()` cannot be used with an array of files.
- Rotation degrees must be 0, 90, 180, or 270.
- Encryption bit length must be 40, 128, or 256.
