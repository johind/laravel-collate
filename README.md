# Manipulate PDFs within Laravel applications.

Collate is a Laravel package that provides an intuitive API for manipulating PDFs. Originally built to prepare documents for AI/LLM ingestion, it handles all the complexities of PDF manipulation, including merging, extracting, encrypting, watermarking and much more besides. All of this is powered by [qpdf](https://qpdf.readthedocs.io/).

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

    // Default filesystem disk for reading/writing PDFs (default: null — uses your app's default disk)
    'default_disk' => env('COLLATE_DISK'),

    // Directory for temporary files during processing (automatically cleaned up)
    'temp_directory' => env('COLLATE_TEMP_DIR', storage_path('app/collate')),
];
```

## Usage

Collate provides two entry points — `open()` for working with and manipulating an existing PDF, and `merge()` for combining multiple files. For read-only operations such as inspecting metadata or counting pages, use `inspect()` instead. All three return a fluent builder that lets you chain operations before saving or returning a response.

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

Switch disks on the fly using `disk()`:

```php
Collate::disk('s3')->open('reports/quarterly.pdf')->save('archive/quarterly.pdf');
```

### Merging PDFs

Combine multiple files into a single document:

```php
Collate::merge(
    'documents/cover.pdf',
    'documents/chapter-1.pdf',
    'documents/chapter-2.pdf',
)->save('documents/book.pdf');
```

For more control, pass a closure to select specific pages:

```php
use Johind\Collate\PendingCollate;

Collate::merge(function (PendingCollate $pdf) {
    $pdf->addPage('documents/cover.pdf');
    $pdf->addPages('documents/appendix.pdf', range: '1-3');
})->save('documents/book.pdf');
```

### Adding Pages

Append entire files or specific pages to an existing document:

```php
Collate::open('contract.pdf')
    ->addPage('signature-page.pdf')
    ->save('signed-contract.pdf');

// Add a specific page from another file
Collate::open('report.pdf')
    ->addPage('appendix.pdf', pageNumber: 3)
    ->save('report-with-appendix.pdf');

// Add a range of pages
Collate::open('report.pdf')
    ->addPages('appendix.pdf', range: '1-5')
    ->save('report-final.pdf');

// Add multiple complete files at once
Collate::open('report.pdf')
    ->addPages(['exhibit-a.pdf', 'exhibit-b.pdf'])
    ->save('report-with-exhibits.pdf');

// Add different page ranges from different files (chain multiple calls)
Collate::open('report.pdf')
    ->addPages('appendix-a.pdf', range: '1-3')
    ->addPages('appendix-b.pdf', range: '2-5')
    ->save('report-final.pdf');
```

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

Note: `onlyPages()` and `removePages()` are mutually exclusive — calling both on the same instance will throw a `BadMethodCallException`.

### Splitting a PDF

Split every page into its own file. The path supports a `{page}` placeholder for the page number:

```php
$paths = Collate::open('multi-page.pdf')
    ->split('pages/page-{page}.pdf');

// $paths → Collection ['pages/page-1.pdf', 'pages/page-2.pdf', ...]
```

> **Note:** Always include `{page}` in your path. Without it, every page will be written to the same destination, with each one overwriting the last.

All operations — page selection, rotation, overlays, etc. — are applied before splitting, so you can chain them freely:

```php
Collate::open('scanned.pdf')
    ->rotate(90)
    ->onlyPages('1-5')
    ->split('pages/page-{page}.pdf');
```

### Rotating Pages

Rotate pages by 90, 180, or 270 degrees:

```php
Collate::open('scanned.pdf')
    ->rotate(90)
    ->save('rotated.pdf');

// Rotate specific pages only
Collate::open('scanned.pdf')
    ->rotate(90, pages: '1-3')
    ->rotate(180, pages: '5')
    ->save('fixed.pdf');
```

### Overlays & Underlays

Add watermarks, letterheads, or backgrounds:

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

For more control, use separate user and owner passwords and restrict specific permissions:

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

| Permission | Effect |
|---|---|
| `print` | Disallow printing |
| `modify` | Disallow modifications |
| `extract` | Disallow text and image extraction |
| `annotate` | Disallow adding annotations |
| `assemble` | Disallow page assembly (inserting, rotating, etc.) |
| `print-highres` | Disallow high-resolution printing |
| `form` | Disallow filling in form fields |
| `modify-other` | Disallow all other modifications |

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

### Flattening

Flatten form fields and annotations into the page content:

```php
Collate::open('form-filled.pdf')
    ->flatten()
    ->save('flattened.pdf');
```

### Linearization

Optimize a PDF for fast web viewing:

```php
Collate::open('large-report.pdf')
    ->linearize()
    ->save('web-optimized.pdf');
```

### Metadata

Read metadata from an existing PDF using `inspect()`:

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
```

Set metadata on the output document:

```php
Collate::open('document.pdf')
    ->withMetadata(
        title: 'Annual Report 2024',
        author: 'Taylor Otwell',
    )
    ->save('branded-report.pdf');
```

### Page Count

Get the number of pages in a document using `inspect()`:

```php
$count = Collate::inspect('document.pdf')->pageCount();
```

`pageCount()` and `metadata()` are also available on the builder if you need them mid-chain:

```php
Collate::open('document.pdf')
    ->when(fn ($pdf) => $pdf->pageCount() > 10, fn ($pdf) => $pdf->onlyPages('1-10'))
    ->save('capped.pdf');
```

## Saving & Responses

### Save to Disk

```php
Collate::open('input.pdf')->save('output.pdf');
```

You can save to a different disk than the one used to read the source by passing a disk name as the second argument:

```php
// Read from local, save to S3
Collate::open('input.pdf')->save('reports/output.pdf', disk: 's3');

// Read from S3, save back to local
Collate::disk('s3')->open('input.pdf')->save('output.pdf', disk: 'local');
```

### Download

Return a download response from a controller:

```php
return Collate::open('invoice.pdf')->download('invoice-2024-001.pdf');
```

### Stream Inline

Display the PDF inline in the browser:

```php
return Collate::open('invoice.pdf')->stream('invoice-2024-001.pdf');
```

### Raw Content

Get the raw PDF binary contents as a string. Useful for APIs, email attachments, or custom storage:

```php
$content = Collate::open('document.pdf')->content();
```

### Returning from Controllers

`PendingCollate` implements Laravel's `Responsable` interface, so you can return it directly from a controller:

```php
public function show()
{
    return Collate::open('invoice.pdf');
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

Collate uses the `Macroable` trait, so you can add custom methods:

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

Contributions are welcome! Please open an issue to discuss bugs, feature requests, or pull requests. I do not provide monetary compensations.

## Security

If you discover a security vulnerability, please send an email rather than opening a GitHub issue.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
