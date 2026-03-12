---
name: collate-pdf-pipeline
description: "Build complex multi-step PDF processing pipelines with Collate. Use when composing operations like merging, splitting, watermarking, encrypting, and assembling documents into real workflows."
tags:
  - laravel
  - php
  - pdf
  - pipeline
  - workflow
---

# Collate PDF Pipeline Patterns

Use this skill when building multi-step PDF processing workflows. For API reference, see the core guideline — this skill focuses on **composition patterns and real-world recipes**.

## Workflow Principles

1. **Chain, don't nest.** Every method on `PendingCollate` returns `$this` — build your pipeline in a single fluent chain.
2. **Order matters for some operations.** `restrict()` must follow `encrypt()`. Page manipulations (`addPages`, `removePages`, `onlyPages`) are applied in call order.
3. **`onlyPages()` and `removePages()` are mutually exclusive.** Pick one strategy per chain.
4. **Use `merge()` for combining, `open()` for transforming.** Don't `open()` one file and `addPages()` for the rest if you're just combining — `merge()` is clearer.

## Composition Patterns

### Sequential Pipeline

Chain operations in the order they should be applied:

```php
Collate::open('report.pdf')
    ->removePages('1')                    // Drop the old cover
    ->addPage('new-cover.pdf', 1)         // Insert new cover at page 1
    ->overlay('watermark.pdf')            // Apply watermark
    ->withMetadata(title: 'Q4 Report')    // Set metadata
    ->linearize()                         // Optimize for web
    ->save('final-report.pdf');
```

### Dynamic Pipeline with Conditionable

Use `->when()` to conditionally apply operations based on runtime values:

```php
Collate::open($request->file('document'))
    ->when($request->boolean('watermark'), fn ($pdf) => $pdf->overlay('watermark.pdf'))
    ->when($request->boolean('flatten'), fn ($pdf) => $pdf->flatten())
    ->when($request->has('password'), fn ($pdf) => $pdf
        ->encrypt($request->input('password'), bitLength: 256)
        ->restrict('print', 'extract')
    )
    ->when($request->boolean('web_optimized'), fn ($pdf) => $pdf->linearize())
    ->save('processed/' . $request->file('document')->hashName());
```

### Merge with Closure for Fine-Grained Control

When you need specific pages from specific files:

```php
Collate::merge(function (PendingCollate $pdf) {
    $pdf->addPage('cover.pdf', 1);
    $pdf->addPages('chapters/intro.pdf');
    $pdf->addPages('chapters/main.pdf', range: '1-20');
    $pdf->addPage('appendix.pdf', 1);
})->withMetadata(title: 'Complete Book', author: 'Publishing Team')
  ->save('book.pdf');
```

### Reusable Steps with Macros

Define macros in a service provider to create reusable pipeline steps:

```php
// In AppServiceProvider::boot()
use Johind\Collate\PendingCollate;

PendingCollate::macro('brand', function () {
    return $this->underlay('assets/letterhead.pdf')
                ->overlay('assets/watermark.pdf')
                ->withMetadata(author: 'Acme Corp');
});

PendingCollate::macro('securePrint', function (string $password) {
    return $this->encrypt($password, bitLength: 256)
                ->restrict('modify', 'extract', 'annotate');
});

// Usage — clean, readable pipelines
Collate::open('report.pdf')
    ->brand()
    ->securePrint('secret')
    ->save('branded-report.pdf');
```

## Cross-Disk Workflows

Read from one storage disk, process, and save to another:

```php
// Download from S3, process, save locally
Collate::fromDisk('s3')
    ->open('uploads/raw-report.pdf')
    ->flatten()
    ->linearize()
    ->toDisk('local')
    ->save('processed/report.pdf');

// Process local file and upload to S3
Collate::open('generated/invoice.pdf')
    ->overlay('assets/stamp.pdf')
    ->toDisk('s3')
    ->save('invoices/2024/invoice-042.pdf');
```

## Real-World Recipes

### Invoice Generation with Cover Page

```php
public function generateInvoicePackage(Order $order): bool
{
    $invoicePath = $this->renderInvoicePdf($order); // Your PDF generation logic

    return Collate::merge(function (PendingCollate $pdf) use ($invoicePath, $order) {
        $pdf->addPage('templates/invoice-cover.pdf', 1);
        $pdf->addPages($invoicePath);
        $pdf->addPages('templates/terms-and-conditions.pdf');
    })
    ->withMetadata(
        title: "Invoice #{$order->number}",
        author: config('app.name'),
    )
    ->linearize()
    ->save("invoices/{$order->number}.pdf");
}
```

### Batch Processing Uploaded Files

```php
public function processUploads(Request $request)
{
    $request->validate(['documents.*' => 'required|file|mimes:pdf']);

    $paths = collect($request->file('documents'))->map(function (UploadedFile $file) {
        return Collate::open($file)
            ->flatten()
            ->overlay('assets/received-stamp.pdf')
            ->withMetadata(title: $file->getClientOriginalName())
            ->toDisk('s3')
            ->save("uploads/{$file->hashName()}");
    });

    return $paths;
}
```

### Branded Document Assembly

Assemble a multi-section document with consistent branding:

```php
public function assembleProposal(Proposal $proposal): bool
{
    $sections = $proposal->sections->map(fn ($s) => $s->pdf_path)->toArray();

    return Collate::merge(
        'templates/proposal-cover.pdf',
        ...$sections,
        'templates/proposal-back.pdf',
    )
    ->underlay('assets/branded-background.pdf')
    ->withMetadata(
        title: $proposal->title,
        author: $proposal->author->name,
        subject: 'Business Proposal',
    )
    ->linearize()
    ->save("proposals/{$proposal->id}.pdf");
}
```

### Secure Document Distribution

Encrypt and restrict permissions for sensitive documents:

```php
public function distributeConfidential(Document $document, User $recipient): StreamedResponse
{
    $password = Str::random(16);

    // Notify recipient of password via separate channel
    $recipient->notify(new DocumentPasswordNotification($document, $password));

    return Collate::open($document->path)
        ->flatten()
        ->encrypt(userPassword: $password, ownerPassword: config('app.owner_password'), bitLength: 256)
        ->restrict('print', 'extract', 'modify', 'annotate')
        ->withMetadata(title: $document->title)
        ->download("{$document->slug}.pdf");
}
```

### Splitting and Re-processing

Split a document, process individual pages, then work with results:

```php
public function extractAndProcess(string $sourcePath): Collection
{
    // Split into individual pages
    $pages = Collate::open($sourcePath)
        ->split("temp/pages/page-{page}.pdf");

    // Process each page individually
    return $pages->map(function (string $pagePath, int $index) {
        return Collate::open($pagePath)
            ->overlay('assets/page-number-overlay.pdf')
            ->rotate($index === 0 ? 0 : 90) // Rotate all but first page
            ->save("processed/page-{$index}.pdf");
    });
}
```

### Conditional Assembly Based on Inspection

Use `inspect()` to make decisions before processing:

```php
public function smartMerge(array $files): bool
{
    // Pre-process each file individually based on its properties
    $processedPaths = collect($files)->map(function (string $file) {
        $pageCount = Collate::inspect($file)->pageCount();

        return Collate::open($file)
            ->when($pageCount > 50, fn ($pdf) => $pdf->linearize())
            ->when($pageCount === 1, fn ($pdf) => $pdf->rotate(0))
            ->flatten()
            ->save("temp/processed-" . basename($file));
    });

    // Merge the pre-processed files. Note: save() returns bool, so use
    // the known output paths for merge input.
    $paths = collect($files)->map(fn ($f) => "temp/processed-" . basename($f));

    return Collate::merge(...$paths->all())
        ->withMetadata(title: 'Combined Document')
        ->save('merged/output.pdf');
}
```

## Testing Pipelines

Use `Collate::fake()` with callback assertions to verify pipeline behavior:

```php
Collate::fake();

// Run your pipeline code...
$this->service->generateReport($order);

// Assert the pipeline applied expected operations
Collate::assertSaved('reports/final.pdf', function (PendingCollateFake $pdf) {
    return $pdf->isEncrypted()
        && $pdf->isLinearized()
        && $pdf->outputDisk() === 's3'
        && count($pdf->additions()) === 3;
});
```
