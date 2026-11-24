# Build Better PDF Workflows in Laravel

[![Tests](https://github.com/johind/laravel-collate/actions/workflows/run-tests.yml/badge.svg)](https://github.com/johind/laravel-collate/actions/workflows/run-tests.yml)
[![Packagist License](https://img.shields.io/badge/Licence-MIT-blue)](http://choosealicense.com/licenses/mit/)
[![Latest Stable Version](https://img.shields.io/packagist/v/johind/collate?label=Stable)](https://packagist.org/packages/johind/collate)
[![Total Downloads](https://img.shields.io/packagist/dt/johind/collate.svg?label=Downloads)](https://packagist.org/packages/johind/collate)

Collate brings fluent PDF workflows to Laravel, giving your app expressive, chainable tools to compose, transform, secure, and deliver PDFs.

Powered by [qpdf](https://qpdf.readthedocs.io/), it supports common operations including merging, splitting, extracting
pages, watermarking, encryption, editing metadata, and web optimisation.

## Requirements

- PHP 8.4+
- Laravel 11, 12, or 13
- [qpdf](https://qpdf.readthedocs.io/) v11.7.1 or higher installed on your system

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

### Ubuntu 26.04 and AppArmor

Ubuntu 26.04 confines `/usr/bin/qpdf` with an AppArmor profile. It only lets qpdf open files ending in `.pdf`, `.qdf`
or `.json`, and only in your home directory, `/tmp`, `/var/tmp`, `/mnt` and `/media` (plus read-only `/usr` and `/opt`).
Collate names its own temporary files accordingly, but if your storage disk or `temp_directory` lives elsewhere, such
as `/var/www`, qpdf fails with `Permission denied`. Allow those paths in `/etc/apparmor.d/local/qpdf`, for example:

```text
/var/www/your-app/storage/** rw,
```

Then reload the profile:

```bash
sudo apparmor_parser -r /etc/apparmor.d/qpdf
```

## Configuration

The published config file (`config/collate.php`) contains four options:

```php
return [
    // Path to the qpdf binary (default: 'qpdf')
    'binary_path' => env('COLLATE_BINARY_PATH', 'qpdf'),

    // Default filesystem disk for reading/writing PDFs (default: null, uses your app's default disk)
    'default_disk' => env('COLLATE_DISK'),

    // Directory for temporary files during processing (automatically cleaned up)
    'temp_directory' => env('COLLATE_TEMP_DIR', storage_path('app/collate')),

    // Maximum number of seconds a single qpdf process may run (default: 60, 0 for no limit)
    'timeout' => env('COLLATE_TIMEOUT', 60),
];
```

The temp directory may briefly hold decrypted documents. Collate writes its temp files so that only the user running PHP
can read them, and creates the directory itself with the same restriction. Collate does not change the permissions of a
directory that already exists.

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

| Category                  | Features                                                                                                                                                                          |
|---------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Getting started**       | [open](#opening-a-pdf) · [choose a disk](#choosing-a-disk) · [save](#save-to-disk) · [download](#download) · [stream](#stream-inline) · [raw content](#raw-content)               |
| **Page operations**       | [merge](#merging-pdfs) · [split](#splitting-a-pdf) · [add](#adding-pages) · [remove](#removing-pages) · [extract](#extracting-pages) · [rotate](#rotating-pages)                  |
| **Overlays & watermarks** | [overlay & underlay](#overlays--underlays)                                                                                                                                        |
| **Security**              | [encrypt / decrypt](#encryption--decryption) · [restrict permissions](#encryption--decryption)                                                                                    |
| **Metadata & inspection** | [read metadata](#reading-metadata) · [write metadata](#writing-metadata) · [strip metadata](#stripping-metadata) · [inspect](#inspecting-a-pdf) · [page count](#reading-metadata) |
| **Optimization**          | [flatten](#flattening) · [linearize](#linearization) · [optimize](#optimization)                                                                                                  |
| **Advanced**              | [conditional operations](#conditional-operations) · [macros](#extending-with-macros) · [debugging](#debugging-the-qpdf-command) · [error handling](#error-handling)               |

## Getting Started

Use `open()` to manipulate an existing PDF, or `merge()` to combine multiple files. Both return a fluent builder you can
chain before saving or returning a response.

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

## Page Operations

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

> [!WARNING]
> `onlyPages()` and `removePages()` are mutually exclusive and neither can be called more than once — calling both,
> or calling either twice, on the same instance will throw a `BadMethodCallException`.

### Page Range Syntax

Anywhere a page range string is accepted (`onlyPages()`, `addPages()`, `removePages()`, `rotate()`), you can
use [qpdf range syntax](https://qpdf.readthedocs.io/en/stable/cli.html#page-ranges):

| Expression | Meaning                         |
|------------|---------------------------------|
| `1-5`      | Pages 1 through 5               |
| `1,3,5`    | Pages 1, 3, and 5               |
| `1-3,7-9`  | Pages 1–3 and 7–9               |
| `z`        | Last page                       |
| `r1`       | Last page, counted from the end |
| `r3-r1`    | Last three pages                |
| `1-z`      | All pages                       |
| `1-z:odd`  | Odd pages only                  |
| `1-z:even` | Even pages only                 |

`:odd` and `:even` may only appear once, at the end of the whole range. `rotate()` also accepts exclusions such as
`1-z,x2` (every page except page 2).

### Splitting a PDF

Split every page into its own file. The path supports a `{page}` placeholder for the page number:

```php
$paths = Collate::open('multi-page.pdf')
    ->split('pages/page-{page}.pdf');

// $paths → Collection ['pages/page-1.pdf', 'pages/page-2.pdf', ...]
```

Without a `{page}` placeholder, the page number is appended before the file extension, so `split('pages/report.pdf')`
writes `pages/report-1.pdf`, `pages/report-2.pdf`, and so on.

If the document is encrypted with `encrypt()`, every split page is encrypted with the same passwords and restrictions.

All operations (page selection, rotation, overlays, etc.) are applied before splitting, so you can chain them freely:

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

## Overlays & Underlays

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

## Encryption & Decryption

Encrypt a document with a password:

```php
Collate::open('confidential.pdf')
    ->encrypt('secret')
    ->save('protected.pdf');
```

The user password is needed to open the document. When you don't pass an owner password, Collate generates a random
one. The owner password lifts any restrictions, so a random one means nobody can remove them. Pass your own owner
password if you need to change the document's permissions later.

For more control, use separate user and owner passwords and restrict specific permissions. Note that `restrict()` must
be called after `encrypt()`:

```php
Collate::open('confidential.pdf')
    ->encrypt(
        userPassword: 'secret',
        ownerPassword: 'more-secret',
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

PDF viewers enforce these restrictions themselves, so treat them as a deterrent rather than access control. Use a user
password when the content itself must stay confidential.

Collate passes passwords to qpdf through temporary files that only the user running PHP can read, never as
command-line arguments, so other users on the server can't see them in the process list. Passwords can't contain line
breaks or NUL bytes.

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

## Metadata & Inspection

### Reading Metadata

Use `inspect()` (a semantic alias for `open()`) for read-only operations like reading metadata or counting pages:

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

`pageCount()` and `metadata()` are also available on the builder if you need them mid-chain, even after a `merge()`:

```php
Collate::merge('doc1.pdf', 'doc2.pdf')
    ->when(fn ($pdf) => $pdf->pageCount() > 10, fn ($pdf) => $pdf->rotate(90))
    ->save('merged.pdf');
```

### Writing Metadata

Set metadata on the output document:

```php
Collate::open('document.pdf')
    ->withMetadata(
        title: 'Annual Report 2024',
        author: 'Taylor Otwell',
    )
    ->save('branded-report.pdf');

// Also accepts a PdfMetadata instance (named parameters override its values)
$meta = Collate::inspect('source.pdf')->metadata();
Collate::open('target.pdf')
    ->withMetadata($meta, author: 'New Author')
    ->withMetadata(title: 'Updated Title')
    ->save('output.pdf');
```

> [!NOTE]
> When you pass a `PdfMetadata` instance, you can override any named fields in the
> same call except `title`. To change the title, call `withMetadata()` again with
> `title:` as shown above.

### Stripping Metadata

Remove all metadata from the output document:

```php
Collate::open('document.pdf')
    ->withoutMetadata()
    ->save('clean.pdf');
```

> [!WARNING]
> `withoutMetadata()` and `withMetadata()` are mutually exclusive. Calling both on the same instance
> will throw a `BadMethodCallException`.

### Inspecting a PDF

Use `inspect()` to query properties of an existing document without modifying it:

```php
$pdf = Collate::inspect('document.pdf');

$pdf->isEncrypted();  // true if the document is encrypted
$pdf->hasPassword();  // true if a password is required to open the document
$pdf->isLinearized(); // true if the document is linearized for fast web viewing
$pdf->pdfVersion();   // e.g. '1.7', '2.0'
$pdf->pageSize();     // PageSize { width: 612.0, height: 792.0 }
$pdf->pageSize(3);    // dimensions of a specific page
```

`inspect()` is a semantic alias for `open()`. You can also call these methods mid-chain on any builder.

`pageSize()` returns the underlying page box dimensions in PDF points, not a rotation-adjusted display size. The returned
`PageSize` object includes conversion helpers:

```php
$size = Collate::inspect('document.pdf')->pageSize();

$size->widthInInches();
$size->heightInInches();
$size->widthInMillimeters();
$size->heightInMillimeters();
```

## Optimization

### Flattening

Flatten form fields and annotations into the page content:

```php
Collate::open('form-filled.pdf')->flatten()->save('flattened.pdf');
```

### Linearization

Optimize a PDF for fast web viewing (progressive loading):

```php
Collate::open('large-report.pdf')->linearize()->save('web-optimized.pdf');
```

### File Size Optimization

Reduce file size by removing redundant data and optimizing internal structures:

```php
Collate::open('bloated.pdf')->optimize()->save('smaller.pdf');
```

When `optimize()` is combined with `linearize()`, qpdf's linearization requirements take precedence over object stream
generation. Other optimization steps still apply.

```php
Collate::open('form-filled.pdf')
    ->flatten()
    ->optimize()
    ->linearize()
    ->save('optimized.pdf');
```

## Advanced

### Conditional Operations

`PendingCollate` uses the `Conditionable` trait, so you can conditionally apply operations:

```php
Collate::open('document.pdf')
    ->when($request->boolean('watermark'), fn ($pdf) => $pdf->overlay('watermark.pdf'))
    ->when($request->boolean('flatten'), fn ($pdf) => $pdf->flatten())
    ->save('output.pdf');
```

### Extending with Macros

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

### Debugging the qpdf Command

Use `dump()` and `dd()` to inspect the underlying qpdf command that Collate builds, without executing it:

```php
Collate::open('document.pdf')
    ->rotate(90)
    ->encrypt('secret')
    ->dump();  // dumps the command and continues the chain

Collate::open('document.pdf')
    ->overlay('watermark.pdf')
    ->dd();    // dumps the command and stops execution
```

Passwords are shown as `********`. The output may still contain file paths.

### Error Handling

Processing and file-handling errors throw `Johind\Collate\Exceptions\CollateException`, which extends PHP's
`RuntimeException`.

When a `qpdf` command fails or exceeds the configured `timeout`, a `Johind\Collate\Exceptions\ProcessFailedException`
(a `CollateException`) is thrown, exposing the `exitCode` and `errorOutput` from the underlying process. Invalid
arguments (bad page ranges, unsupported rotation degrees, etc.) throw standard `InvalidArgumentException` or
`BadMethodCallException` instances.

Paths are resolved the same way as Laravel's `Storage` facade. A path that escapes the disk root (such as `../.env`)
throws `League\Flysystem\PathTraversalDetected`, and a missing remote file throws
`Illuminate\Contracts\Filesystem\FileNotFoundException`.

```php
use Johind\Collate\Exceptions\ProcessFailedException;

try {
    Collate::open('corrupted.pdf')->save('output.pdf');
} catch (ProcessFailedException $e) {
    $e->exitCode;    // qpdf exit code
    $e->errorOutput; // stderr from qpdf
}
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for your help in keeping Collate stable! I am primarily looking for contributions that focus on fixing bugs,
improving error handling or enhancing performance. If you have an idea for a new feature, please open an issue to
discuss it with me first, since I want to ensure that the scope of the package remains focused. Please note that I do
not provide monetary compensation for contributions.

Before submitting a code change, run `composer check`. Collate uses Pest for all tests; please do not add PHPUnit test
classes or run the suite through `vendor/bin/phpunit`.

## Security

If you discover a security vulnerability, please report it privately as described in the [security policy](SECURITY.md)
rather than opening a public GitHub issue.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
