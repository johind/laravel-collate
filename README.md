# Collate — PDF manipulation for Laravel

[![Tests](https://github.com/johind/laravel-collate/actions/workflows/run-tests.yml/badge.svg)](https://github.com/johind/laravel-collate/actions/workflows/run-tests.yml)
[![Packagist License](https://img.shields.io/badge/Licence-MIT-blue)](http://choosealicense.com/licenses/mit/)
[![Latest Stable Version](https://img.shields.io/packagist/v/johind/collate?label=Stable)](https://packagist.org/packages/johind/collate)
[![Total Downloads](https://img.shields.io/packagist/dt/johind/collate.svg?label=Downloads)](https://packagist.org/packages/johind/collate)

Collate is a Laravel package that provides a fluent API for manipulating PDFs.

It supports common operations including merging, splitting, extracting pages,
watermarking, encryption, metadata editing, and web optimization, all powered
by [qpdf](https://qpdf.readthedocs.io/).

## Requirements

- PHP 8.4+
- Laravel 11 or 12
- [qpdf](https://qpdf.readthedocs.io/) v11.0.0 or higher installed on your system

## Installation

Install the package via Composer:

```bash
composer require johind/collate
```

Then run the install command to publish the configuration and verify that `qpdf` is available:

```bash
php artisan collate:install
```

You may also publish the config file manually:

```bash
php artisan vendor:publish --tag="collate-config"
```

## Configuration

The published config file (`config/collate.php`) contains three options:

```php
return [
    // Path to the qpdf binary (default: 'qpdf')
    'binary_path' => env('COLLATE_BINARY_PATH', 'qpdf'),

    // Default filesystem disk for reading/writing PDFs (default: null, uses your app's default disk)
    'default_disk' => env('COLLATE_DISK'),

    // Directory for temporary files during processing (automatically cleaned up)
    'temp_directory' => env('COLLATE_TEMP_DIR', storage_path('app/collate')),
];
```

## Quick Examples

```php
use Johind\Collate\Facades\Collate;

// Prepare an uploaded document for archival
Collate::open($request->file('document'))
    ->addPages('legal/standard-terms.pdf')
    ->withMetadata(title: 'Client Report 2025')
    ->encrypt('client-password')
    ->toDisk('s3')
    ->save('reports/final.pdf');

// Merge and optimize multiple files for web viewing
Collate::merge('cover.pdf', 'chapter-1.pdf', 'chapter-2.pdf')
    ->overlay('branding/watermark.pdf')
    ->linearize()
    ->save('book.pdf');
```

## Capabilities

[Open](#opening-a-pdf) · [merge](#merging-pdfs) · [split](#splitting-a-pdf) · [add](#adding-pages) / [remove](#removing-pages) / [extract](#extracting-pages)
pages · [rotate](#rotating-pages) · [overlay & underlay](#overlays--underlays) · [encrypt / decrypt](#encryption--decryption) · [restrict permissions](#encryption--decryption) · [flatten · linearize](#flattening--linearization) · [read & write metadata](#inspecting-pdfs) · [page count](#inspecting-pdfs) · [save to disk](#save-to-disk) · [download](#download) · [stream](#stream-inline) · [raw content](#raw-content) · [conditional operations](#conditional-operations) · [macros](#extending-with-macros).

## Usage

Use `open()` to manipulate an existing PDF, `merge()` to combine multiple files, or `inspect()` (a semantic alias for `open()`) for read-only operations like metadata and page count. All three return a fluent builder you can chain before saving or returning a response.

### Opening a PDF

```php
use Johind\Collate\Facades\Collate;

$pending = Collate::open('invoices/2024-001.pdf');
```

Files are resolved from your configured filesystem disk. You can also pass `UploadedFile` instances:

```php
Collate::open($request->file('document'));
```

### Choosing a Disk

Switch disks on the fly using `fromDisk()`:

```php
Collate::fromDisk('s3')->open('reports/quarterly.pdf')->toDisk('local')->save('quarterly.pdf');
```

### Merging PDFs

Combine multiple files into a single document:

```php
Collate::merge(
    'documents/cover.pdf',
    'documents/chapter-1.pdf',
    'documents/chapter-2.pdf',
)->save('documents/book.pdf');

// Also accepts a single array of files
Collate::merge(['doc1.pdf', 'doc2.pdf'])->save('merged.pdf');
```

For more control, pass a closure to select specific pages:

```php
use Johind\Collate\PendingCollate;

Collate::merge(function (PendingCollate $pdf) {
    $pdf->addPage('documents/cover.pdf', 1);
    $pdf->addPages('documents/appendix.pdf', range: '1-3');
})->save('documents/book.pdf');
```

### Adding Pages

Append entire files or specific pages to an existing document:

```php
Collate::open('report.pdf')
    ->addPage('appendix.pdf', pageNumber: 3)       // single page from another file
    ->addPages('terms.pdf', range: '1-5')          // page range
    ->addPages(['exhibit-a.pdf', 'exhibit-b.pdf']) // multiple complete files
    ->save('final-report.pdf');
```

> [!IMPORTANT]
> The `range` parameter cannot be used when passing an array of files. 
> Chain multiple `addPages()` calls instead.

### Removing Pages

Remove specific pages from a document:

```php
Collate::open('document.pdf')
    ->removePage(3)
    ->save('without-page-3.pdf');

Collate::open('document.pdf')
    ->removePages([1, 3, 5])
    ->save('trimmed.pdf');

// Remove a range of pages
Collate::open('document.pdf')
    ->removePages('5-10')
    ->save('trimmed.pdf');
```

### Extracting Pages

Keep only the pages you need using `onlyPages()`:

```php
Collate::open('document.pdf')
    ->onlyPages([1, 2, 3])
    ->save('first-three-pages.pdf');

// Also accepts qpdf range expressions
Collate::open('document.pdf')
    ->onlyPages('1-5,8,11-z')
    ->save('selected-pages.pdf');
```

Anywhere a page range string is accepted (`onlyPages()`, `addPages()`, `removePages()`, `rotate()`), you can
use [qpdf range syntax](https://qpdf.readthedocs.io/en/stable/cli.html#page-ranges):

| Expression | Meaning           |
|------------|-------------------|
| `1-5`      | Pages 1 through 5 |
| `1,3,5`    | Pages 1, 3, and 5 |
| `1-3,7-9`  | Pages 1–3 and 7–9 |
| `z`        | Last page         |
| `1-z`      | All pages         |

> [!WARNING]
> `onlyPages()` and `removePages()` are mutually exclusive and neither can be called more than once — calling both,
> or calling either twice, on the same instance will throw a `BadMethodCallException`.

### Splitting a PDF

Split every page into its own file. The path supports a `{page}` placeholder for the page number:

```php
$paths = Collate::open('multi-page.pdf')
    ->split('pages/page-{page}.pdf');

// $paths → Collection ['pages/page-1.pdf', 'pages/page-2.pdf', ...]
```

> [!IMPORTANT]
> Always include `{page}` in your path. Without it, every page will be written 
> to the same destination, with each one overwriting the last.

All operations — page selection, rotation, overlays, etc. — are applied before splitting, so you can chain them freely:

```php
Collate::open('scanned.pdf')
    ->rotate(90)
    ->onlyPages('1-5')
    ->split('pages/page-{page}.pdf');
```

### Rotating Pages

Rotate pages by 0, 90, 180, or 270 degrees:

```php
Collate::open('scanned.pdf')
    ->rotate(90)
    ->save('rotated.pdf');

// Rotate specific pages only
Collate::open('scanned.pdf')
    ->rotate(90, range: '1-3')
    ->rotate(180, range: '5')
    ->save('fixed.pdf');
```

### Overlays & Underlays

Add watermarks, letterheads, or backgrounds. Both methods accept a disk path or an `UploadedFile` instance:

```php
// Overlay (on top — watermarks, stamps)
Collate::open('document.pdf')
    ->overlay('watermark.pdf')
    ->save('watermarked.pdf');

// Underlay (behind — backgrounds, letterheads)
Collate::open('content.pdf')
    ->underlay('letterhead.pdf')
    ->save('branded.pdf');
```

### Encryption & Decryption

Encrypt a document with a password:

```php
Collate::open('confidential.pdf')
    ->encrypt('secret')
    ->save('protected.pdf');
```

For more control, use separate user and owner passwords and restrict specific permissions. Note that `restrict()` must
be called after `encrypt()`:

```php
Collate::open('confidential.pdf')
    ->encrypt(
        userPassword: 'read-only',
        ownerPassword: 'full-access',
        bitLength: 256,
    )
    ->restrict('print', 'extract')
    ->save('locked.pdf');
```

The following permissions can be passed to `restrict()`:

| Permission      | Effect                                             |
|-----------------|----------------------------------------------------|
| `print`         | Disallow printing                                  |
| `modify`        | Disallow modifications                             |
| `extract`       | Disallow text and image extraction                 |
| `annotate`      | Disallow adding annotations                        |
| `assemble`      | Disallow page assembly (inserting, rotating, etc.) |
| `print-highres` | Disallow high-resolution printing                  |
| `form`          | Disallow filling in form fields                    |
| `modify-other`  | Disallow all other modifications                   |

Decrypt a password-protected document:

```php
Collate::open('locked.pdf')
    ->decrypt('secret')
    ->save('unlocked.pdf');
```

Re-encrypt with a new password in one step:

```php
Collate::open('locked.pdf')
    ->decrypt('old-password')
    ->encrypt('new-password')
    ->save('re-encrypted.pdf');
```

### Flattening & Linearization

Flatten form fields and annotations into the page content, or optimize a PDF for fast web viewing:

```php
Collate::open('form-filled.pdf')->flatten()->save('flattened.pdf');

Collate::open('large-report.pdf')->linearize()->save('web-optimized.pdf');
```

### Inspecting PDFs

Use `inspect()` for read-only operations like reading metadata or counting pages:

```php
$meta = Collate::inspect('document.pdf')->metadata();

$meta->title;        // 'Quarterly Report'
$meta->author;       // 'Taylor Otwell'
$meta->subject;
$meta->keywords;
$meta->creator;
$meta->producer;
$meta->creationDate;
$meta->modDate;

$count = Collate::inspect('document.pdf')->pageCount();
```

Set metadata on the output document:

```php
Collate::open('document.pdf')
    ->withMetadata(
        title: 'Annual Report 2024',
        author: 'Taylor Otwell',
    )
    ->save('branded-report.pdf');

// Also accepts a PdfMetadata instance — named parameters override its values
$meta = Collate::inspect('source.pdf')->metadata();
Collate::open('target.pdf')->withMetadata($meta, author: 'New Author')->save('output.pdf');
```

`pageCount()` and `metadata()` are also available on the builder if you need them mid-chain, even after a `merge()`:

```php
Collate::merge('doc1.pdf', 'doc2.pdf')
    ->when(fn ($pdf) => $pdf->pageCount() > 10, fn ($pdf) => $pdf->rotate(90))
    ->save('merged.pdf');
```

## Saving & Responses

### Save to Disk

```php
Collate::open('input.pdf')->save('output.pdf');
```

### Download

Return a download response from a controller. The filename defaults to `document.pdf` when omitted:

```php
return Collate::open('invoice.pdf')
    ->encrypt('client-password')
    ->download('invoice-2024-001.pdf');
```

### Stream Inline

Display the PDF inline in the browser. The filename defaults to `document.pdf` when omitted:

```php
return Collate::merge('cover.pdf', 'report.pdf')
    ->linearize()
    ->stream('quarterly-report.pdf');
```

### Raw Content

Get the raw PDF binary contents as a string. Useful for APIs, email attachments, or custom storage:

```php
$content = Collate::open('document.pdf')->content();
```

### Returning from Controllers

`PendingCollate` implements Laravel's `Responsable` interface, so you can return it directly from a controller. By
default, the PDF is displayed in the browser:

```php
public function show()
{
    return Collate::open('invoice.pdf');
}
```

## Error Handling

All exceptions thrown by Collate extend `Johind\Collate\Exceptions\CollateException`, which itself extends PHP's
`RuntimeException`.

When a `qpdf` command fails, a `Johind\Collate\Exceptions\ProcessFailedException` is thrown, exposing the `exitCode`
and `errorOutput` from the underlying process. Invalid arguments (bad page ranges, unsupported rotation degrees, etc.)
throw standard `InvalidArgumentException` or `BadMethodCallException` instances.

```php
use Johind\Collate\Exceptions\ProcessFailedException;

try {
    Collate::open('corrupted.pdf')->save('output.pdf');
} catch (ProcessFailedException $e) {
    $e->exitCode;    // qpdf exit code
    $e->errorOutput; // stderr from qpdf
}
```

## Conditional Operations

`PendingCollate` uses the `Conditionable` trait, so you can conditionally apply operations:

```php
Collate::open('document.pdf')
    ->when($request->boolean('watermark'), fn ($pdf) => $pdf->overlay('watermark.pdf'))
    ->when($request->boolean('flatten'), fn ($pdf) => $pdf->flatten())
    ->save('output.pdf');
```

## Extending with Macros

Register macros on `PendingCollate` to add chainable operations:

```php
use Johind\Collate\PendingCollate;

PendingCollate::macro('stamp', function () {
    return $this->overlay('assets/stamp.pdf');
});

Collate::open('contract.pdf')->stamp()->save('stamped.pdf');
```

Register macros on `Collate` to add new entry points:

```php
use Johind\Collate\Collate;

Collate::macro('openInvoice', function (int $invoiceId) {
    return $this->open("invoices/{$invoiceId}.pdf");
});

Collate::openInvoice(2024001)->download();
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

I appreciate your help in keeping Collate stable! I'm primarily looking for contributions that focus on fixing bugs,
improving error handling, or performance. If you have an idea for a new feature, please open an issue to discuss it with
me first, as I want to keep the package's scope focused. Please note that I do not provide monetary compensation for
contributions.

## Security

If you discover a security vulnerability, please send an email rather than opening a GitHub issue.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
