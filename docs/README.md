# Slimmer

> A PHP library for advanced file compression — PDF, images, and archives.

**Slimmer** supports high-quality PDF optimization and image compression via [Ghostscript](https://www.ghostscript.com/) and PHP's GD extension, plus directory/file archiving via `tar` (`.tar.gz` / `.tar.zst`).

---

## Features

- **PDF optimization** — reduce file size with Ghostscript quality presets
- **Image compression** — JPG & PNG with configurable quality and dimensions
- **Tar archiving** — `.tar.gz`, `.tar.zst` or `.tar.bz2`, with extraction, listing and retention management
- **Zip archiving** — `.zip` compression and extraction
- **In-memory I/O** — pass strings or streams instead of files
- **Dry-run support** — inspect commands before executing
- **Timeout control** — prevent runaway processes

---

## Quick Start

```bash
composer require gomdim-apps/slimmer
```

```php
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

$ratio = (new PdfOptimizer())
    ->withQuality('screen')
    ->optimize('input.pdf', 'output.pdf');

echo round($ratio * 100, 2) . '% reduced';
```

---

## Navigation

- [Installation & Requirements](installation.md)
- [PDF Optimizer](pdf-optimizer.md)
- [Image Optimizer](image-optimizer.md)
- [Streams & Buffers](streams-buffers.md)
- [Tar Compression](tar-compression.md)
- [Zip Compression](zip-compression.md)
- [Error Handling](error-handling.md)
- [Troubleshooting](troubleshooting.md)
