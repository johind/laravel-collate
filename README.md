# Manipulate PDFs within Laravel applications.

Collate is a Laravel package that provides a beautiful, fluent API for manipulating PDFs. Merge documents, extract pages, encrypt files, add watermarks, and more — all powered by [qpdf](https://qpdf.readthedocs.io/).

## Requirements

- PHP 8.4+
- Laravel 11 or 12
- [qpdf](https://qpdf.readthedocs.io/) installed on your system

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

Collate provides two entry points — `open()` for working with an existing PDF, and `merge()` for combining multiple files. Both return a fluent builder that lets you chain operations before saving or returning a response.

### Opening a PDF

```php
use Johind\Collate\Facades\Collate;

$pending = Collate::open('invoices/2024-001.pdf');
```

Files are resolved from your configured filesystem disk. You can also pass absolute paths or `UploadedFile` instances:

```php
// Absolute path
Collate::open('/tmp/uploaded.pdf');

// Uploaded file
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

Keep only the pages you need:

```php
Collate::open('document.pdf')
    ->onlyPages([1, 2, 3])
    ->save('first-three-pages.pdf');

// Also accepts qpdf range expressions
Collate::open('document.pdf')
    ->onlyPages('1-5,8,11-z')
    ->save('selected-pages.pdf');
```

### Splitting a PDF

Split every page into its own file. The path supports a `{page}` placeholder:

```php
$paths = Collate::open('multi-page.pdf')
    ->split('pages/page-{page}.pdf');

// $paths → Collection ['pages/page-1.pdf', 'pages/page-2.pdf', ...]
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

### Encryption & Passwords

Protect a document with a password:

```php
Collate::open('confidential.pdf')
    ->password('secret')
    ->save('protected.pdf');
```

For more control, use `encrypt()` with separate user and owner passwords:

```php
Collate::open('confidential.pdf')
    ->encrypt(
        userPassword: 'read-only',
        ownerPassword: 'full-access',
        bitLength: 256,
    )
    ->preventPrinting()
    ->save('locked.pdf');
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

Read metadata from an existing PDF:

```php
$meta = Collate::open('document.pdf')->metadata();

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
    ->setMetadata(
        title: 'Annual Report 2024',
        author: 'Jori Hinderfeld',
    )
    ->save('branded-report.pdf');
```

### Page Count

Get the number of pages in a document:

```php
$count = Collate::open('document.pdf')->pageCount();
```

## Saving & Responses

### Save to Disk

```php
Collate::open('input.pdf')->save('output.pdf');
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

### Base64

Encode the result as a base64 string (useful for APIs or email attachments):

```php
$base64 = Collate::open('document.pdf')->toBase64();
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

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
