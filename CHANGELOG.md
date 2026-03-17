# Changelog

All notable changes to `Collate` will be documented in this file.

## 1.5.0 - 2026-03-17

### Added
- `removePages()` now supports positional `:odd`/`:even` modifiers (e.g., `removePages('1-z:odd')`).

### Fixed
- `removePages()` now correctly handles selections that result in zero pages without failing.

### Documentation
- Clarified advanced page range syntax.
- Documented `withMetadata()` overrides when passing `PdfMetadata`, including title handling.

## 1.4.3 - 2026-03-16

### Added
- `dump()` and `dd()` methods on `PendingCollate` to inspect the built qpdf command for debugging

### Documentation
- Document `dump()` and `dd()` debugging methods in README
- Add `dump()` and `dd()` to AI core guideline

## 1.4.2 - 2026-03-13

### Fixed
- Cast `config()` values to expected types in service provider
- Use `Config::string()` to resolve binary path in `InstallCommand`
- Add iterable value types to variadic `merge()` parameters
- Resolve PHPStan max-level errors in `PendingCollate`

### Chore
- Set PHPStan analysis level to max

### Documentation
- Improve readability by using GitHub markdown alerts
- Improve usage wording

## 1.4.1 - 2026-03-12

### Added
- **AI Core Guideline:** Added a comprehensive API reference and usage guide for AI assistants in `resources/boost/guidelines/core.blade.php`
- **PDF Pipeline Skill:** Added `collate-pdf-pipeline` skill focusing on composition patterns and real-world workflows (replaces `collate-pdf-manipulation`)

### Documentation
- Improved AI guidance with practical pipeline patterns, cross-disk workflows, and real-world recipes

## 1.4.0 - 2026-03-12

This release significantly improves the fluent API, adds professional-grade performance optimizations, and standardizes terminology across the package.

### Changed (Breaking API Update)
- **Disk Symmetry:** Renamed `Collate::disk()` to `Collate::fromDisk()` to better describe the source data flow.
- **Enforced toDisk():** Removed the optional `$disk` parameter from `PendingCollate::save()`. You must now use the fluent `toDisk()` method to specify a destination disk (e.g., `Collate::open($file)->toDisk('s3')->save('path.pdf')`).
- **Standardized Terminology:** Renamed the `$pages` parameter to `$range` across `rotate()`, `removePages()`, and `onlyPages()` for consistency with `addPages()`.
- **Named Arguments:** If you are using named arguments, you must update `pages:` to `range:` in these methods.

### Added
- **Fluent toDisk():** Added `PendingCollate::toDisk()` to explicitly set the storage disk for output files, providing clear symmetry with `fromDisk()`.
- **Enhanced Metadata Handling:** `PendingCollate::withMetadata()` now accepts a `PdfMetadata` instance as its first argument. Subsequent named arguments can still be used to override specific fields.
- **Flexible Merging:** `Collate::merge()` now natively accepts a single array of files, or mixed arrays and strings, while maintaining support for closures.

### Optimized
- **Execution Memoization:** The underlying `qpdf` process is now memoized. Subsequent calls to `save()`, `content()`, `stream()`, or `download()` on the same instance will reuse the previously generated PDF instead of re-running the full pipeline. Cache is automatically cleared when mutations occur.
- **Page Count Caching:** File page counts are now cached within the request lifecycle, and the total document page count is memoized to avoid redundant shell commands.
- **Memory Efficient Ranges:** Refactored `removePages()` to use boundary-based interval merging. This avoids creating massive internal integer arrays for large documents, significantly reducing memory overhead.

### Fixed
- **Metadata Mapping:** Fixed a bug where `withMetadata()` would fail to correctly map fields when passed a `PdfMetadata` object due to key casing mismatches.
- **Documentation:** Fixed a critical error in the README where the removed `save()` disk parameter was still documented.

### Documentation
- Updated the "Quick Example" in the `README.md` to show a more comprehensive, real-world document engineering workflow.
- Updated all examples to reflect the new `fromDisk()`, `toDisk()`, and `$range` naming conventions.

## 1.3.0 - 2026-03-12

This release standardizes the page selection parameter across the builder and improves the documentation to better showcase the package's document engineering capabilities.

### Changed (Breaking API Update)
- Standardized the parameter name for page selections to `$range` across `rotate()`, `removePages()`, and `onlyPages()`.

### Documentation
- Updated the "Quick Example" in the `README.md` to show a more comprehensive, real-world document preparation workflow (including S3 integration, rotation, underlays, metadata, and security).
- Standardized all `README.md` examples and code snippets to reflect the new `$range` parameter naming.

## 1.2.1 - 2026-03-11

This release adds support for the 'z' notation (referring to the final page of a document) in `removePages()` and `addPages()`.

### Added
- Added support for 'z' in `removePages()` (e.g., `->removePages('z')` to remove the last page).
- Verified support for 'z' in `addPages()` ranges.

### Documentation
- Updated `README.md` to clarify supported page ranges and remove unimplemented `r` (reverse) notation.

## 1.2.0 - 2026-03-11

This release improves API consistency by strictly enforcing single-page semantics for the `addPage` method.

### Changed (Breaking API Update)
- The `addPage()` method now strictly requires a `$pageNumber` parameter. It no longer silently accepts whole documents.
- The `addPages()` method has been refactored to eliminate recursion, improving performance and establishing a single source of truth for file resolution.

### Upgrade Guide
If you were previously using `addPage('file.pdf')` to add an entire document, you must update your code to use the plural method `addPages('file.pdf')` instead.

## 1.1.0 - 2026-03-11

This release addresses critical boundary bugs in page selection, improves compatibility with modern versions of `qpdf`, and enhances the extensibility of the builder.

### Fixed
- Resolved a boundary bug in `removePages()` that caused `ProcessFailedException` when attempting to remove the final page of a document.
- Fixed a process failure when using 40-bit or 128-bit encryption with `qpdf` v11+ by automatically appending the `--allow-weak-crypto` flag.

### Added
- Added the `Macroable` trait to the `PendingCollate` class, allowing users to extend the builder with custom macros as promised in the documentation.

### Changed
- Improved error handling: `removePages()` and `onlyPages()` now throw a descriptive `BadMethodCallException` if called without a source file (e.g., in a `merge()` chain).
- Updated internal validation for `removePages()` to be more robust against out-of-bounds selections.

## 1.0.0 - 2026-03-11

Initial release of Collate, providing a fluent API for PDF manipulation in Laravel powered by `qpdf`.

### Features
- Merge multiple PDFs with specific page ranges.
- Split documents into individual pages.
- Rotate pages (90, 180, 270 degrees).
- Overlay (watermark) and underlay (background) support.
- PDF encryption with password protection and permission restrictions.
- PDF decryption of password-protected documents.
- PDF linearization (fast web viewing) and flattening.
- Metadata and page count extraction.
- Support for local and remote filesystem disks.
- Robust test fakes for application-level testing.
