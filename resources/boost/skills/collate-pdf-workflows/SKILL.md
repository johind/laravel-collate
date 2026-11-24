---
name: collate-pdf-workflows
description: Build Collate PDF workflows, including merging, splitting, watermarking, encrypting, metadata, disks, and test fakes.
tags:
  - laravel
  - php
  - pdf
  - workflows
---

# Collate PDF Workflows

Use this skill when a task needs more than one PDF operation or needs tests around Collate behavior.

## Workflow Checklist

- Import `Johind\Collate\Facades\Collate`; import `Johind\Collate\PendingCollate` when using merge closures.
- Prefer one fluent chain per output PDF. Use `->when()` for runtime options instead of branching into duplicated chains.
- Use `merge()` for document assembly. Use `open()` when transforming a source PDF. Use `inspect()` for reads before deciding what to do.
- Apply incompatible choices once: either `onlyPages()` or `removePages()`, and either `withMetadata()` or `withoutMetadata()`.
- Call `encrypt()` before `restrict()`. Prefer `bitLength: 256`.
- Remember `save()` returns `bool`, not the saved path. Keep the destination path in a variable if later code must use it.
- Use `split('dir/page-{page}.pdf')`. Without `{page}`, Collate appends `-1`, `-2`, … before the extension.

## Transform One PDF

```php
use Johind\Collate\Facades\Collate;

$output = 'reports/final.pdf';

Collate::open($request->file('document'))
    ->when($request->boolean('flatten'), fn ($pdf) => $pdf->flatten())
    ->when($request->boolean('watermark'), fn ($pdf) => $pdf->overlay('assets/watermark.pdf'))
    ->withMetadata(title: 'Client Report', author: config('app.name'))
    ->linearize()
    ->save($output);
```

## Assemble Specific Pages

Use a merge closure when each input needs different page selection.

```php
use Johind\Collate\Facades\Collate;
use Johind\Collate\PendingCollate;

Collate::merge(function (PendingCollate $pdf): void {
    $pdf->addPage('templates/cover.pdf', 1);
    $pdf->addPages('chapters/intro.pdf');
    $pdf->addPages('chapters/body.pdf', '1-20');
    $pdf->addPages('templates/appendix.pdf', '1-z:odd');
})
    ->withMetadata(title: 'Complete Packet')
    ->linearize()
    ->save('packets/complete.pdf');
```

## Cross-Disk Workflows

Use `fromDisk()` for inputs and `toDisk()` for outputs.

```php
Collate::fromDisk('s3')
    ->open('incoming/report.pdf')
    ->flatten()
    ->toDisk('local')
    ->save('processed/report.pdf');
```

## Testing Workflows

Fake Collate and assert the observable Collate operations, not qpdf internals.

```php
use Johind\Collate\Facades\Collate;
use Johind\Collate\PendingCollateFake;

Collate::fake();

$service->process('source.pdf');

Collate::assertSaved('processed/source.pdf', function (PendingCollateFake $pdf): bool {
    return $pdf->sourcePath() === 'source.pdf'
        && $pdf->isFlattened()
        && $pdf->isLinearized()
        && $pdf->outputDisk() === 's3';
});
```
