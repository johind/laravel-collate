## Collate

Collate is a Laravel PDF manipulation package powered by qpdf. Use the `Johind\Collate\Facades\Collate` facade.

### Essentials

- Requirements: PHP 8.4+, Laravel 11/12/13, qpdf 11.7.1+. Prefer `php artisan collate:install` to publish config and verify qpdf.
- Entry points: `Collate::open($file)` transforms one PDF, `Collate::inspect($file)` reads metadata/page info, and `Collate::merge(...$files)` combines PDFs. Inputs may be storage paths or `UploadedFile` instances; `merge()` also accepts arrays and a closure receiving `PendingCollate`.
- Disks: `Collate::fromDisk('s3')` selects the input disk and returns a cloned Collate instance. `->toDisk('local')` selects the output disk for a pending operation.
- Output: `save($path)` returns `bool`; `download($filename)` and `stream($filename)` return PDF responses; `content()` returns binary PDF contents. `split($path)` returns `Collection<int, string>` and should normally use a `{page}` placeholder.

### Guardrails

- Use `merge()` for simple document assembly; use `open()` when transforming an existing source.
- `restrict()` must follow `encrypt()`. Use 256-bit encryption unless the user explicitly needs legacy compatibility.
- Do not combine `onlyPages()` with `removePages()` on the same pending PDF.
- Do not combine `withMetadata()` with `withoutMetadata()` on the same pending PDF.
- Do not pass a page range when `addPages()` receives an array; chain separate `addPages($file, $range)` calls instead.
- Page ranges follow qpdf syntax such as `1-5`, `1,3,5`, `z`, `1-z`, and `1-z:odd` / `1-z:even`.
- `dump()` and `dd()` reveal the generated qpdf command and may expose file paths or passwords.
- In tests, use `Collate::fake()` with `assertSaved()`, `assertDownloaded()`, `assertStreamed()`, or `assertSplit()` and callback assertions against `PendingCollateFake`.
