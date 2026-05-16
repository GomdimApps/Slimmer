<div align="center">
  <img src="docs/_media/logo.png" alt="Slimmer Logo" width="180" />
</div>

# Slimmer

> Advanced file compression for PHP — PDF · Images · Archives

A PHP library for compressing PDFs, images, and directories with minimal dependencies. Uses [Ghostscript](https://www.ghostscript.com/) for PDFs, PHP's `ext-gd` for images, and system `tar` for archives.

[![Packagist](https://img.shields.io/packagist/v/gomdim-apps/slimmer)](https://packagist.org/packages/gomdim-apps/slimmer)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Quick Start

```bash
composer require gomdim-apps/slimmer
```

### PDF Optimization

```php
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

$ratio = (new PdfOptimizer())
    ->withQuality('screen')
    ->optimize('input.pdf', 'output.pdf');
```

### Image Compression

```php
use GomdimApps\Slimmer\Optimizers\ImageOptimizer;

$ratio = (new ImageOptimizer())
    ->withQuality(75)
    ->optimize('input.jpg', 'output.jpg');
```

### Tar Archiving

```php
use GomdimApps\Slimmer\Optimizers\CompressTar;

$ratio = (new CompressTar())
    ->withFormat('zst')
    ->optimize('/path/to/dir', 'output.tar.zst');
```

## Requirements

- **PHP**: >= 8.2
- **ext-gd**: For image compression
- **Ghostscript** (`gs`): For PDF optimization
- **tar**: For archiving
- **zstd** *(optional)*: For `.tar.zst` compression

## Features

- 📦 **PDF optimization** with quality presets
- 🖼️ **Image compression** (JPG, PNG)
- 📂 **Tar archiving** (`.tar.gz`, `.tar.zst`)
- 💾 **In-memory I/O** via `fromString()` / `fromStream()`
- 🔍 **Dry-run mode** for command inspection
- ⏱️ **Timeout control** to prevent runaway processes
- 🎯 **Retention management** for archives

## Documentation

Full documentation is available at [**GomdimApps.github.io/Slimmer**](https://GomdimApps.github.io/Slimmer)

Key sections:
- [Installation & Requirements](https://GomdimApps.github.io/Slimmer/#/installation)
- [PDF Optimizer](https://GomdimApps.github.io/Slimmer/#/pdf-optimizer)
- [Image Optimizer](https://GomdimApps.github.io/Slimmer/#/image-optimizer)
- [Tar Compression](https://GomdimApps.github.io/Slimmer/#/tar-compression)
- [Streams & Buffers](https://GomdimApps.github.io/Slimmer/#/streams-buffers)
- [Error Handling](https://GomdimApps.github.io/Slimmer/#/error-handling)
- [Troubleshooting](https://GomdimApps.github.io/Slimmer/#/troubleshooting)

## Docker Testing

Run the test suite in an isolated environment:

```bash
make test
```

## License

MIT
