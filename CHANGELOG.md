# Changelog

All notable changes to `Collate` will be documented in this file.

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
