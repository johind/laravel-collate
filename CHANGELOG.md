# Changelog

All notable changes to `Collate` will be documented in this file.

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
