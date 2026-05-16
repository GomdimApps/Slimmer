# Slimmer

A PHP library for advanced file compression. Currently focused on high-quality PDF optimization using Ghostscript.

## Requirements

- **PHP**: >= 8.2
- **External**: [Ghostscript](https://www.ghostscript.com/) (`gs`) must be installed.

## Installation

```bash
composer require gomdim-apps/slimmer
```

## Basic Usage

```php
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

$optimizer = new PdfOptimizer();

// Returns float ratio of reduction (e.g., 0.4523 = 45.23% reduction)
$ratio = $optimizer
    ->withQuality('screen')
    ->optimize('input.pdf', 'output.pdf');
```

## Advanced Configuration

### Custom Ghostscript Binary
If Ghostscript is not in your PATH or you have a specific version:

```php
use GomdimApps\Slimmer\Engines\GhostscriptEngine;
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

$engine = new GhostscriptEngine('/usr/local/bin/gs');
$optimizer = new PdfOptimizer($engine);
```

### PDF Quality Presets
The `withQuality()` method accepts the following presets:

| Preset | DPI | Description |
|---|---|---|
| `screen` | 72 | Smallest size, lowest quality. Best for web. |
| `ebook` | 150 | Balanced quality and size. (Default) |
| `printer` | 300 | High quality for printing. |
| `prepress` | 300 | Maximum quality, color preserving. |
| `default` | - | System default (usually matches `printer`). |
| `screen` | 72 | Smallest size, lowest quality. Best for web. |

## Image Optimization

Slimmer supports compressing images (JPG, PNG) while maintaining their original dimensions.

```php
use GomdimApps\Slimmer\Optimizers\ImageOptimizer;

$optimizer = new ImageOptimizer();

// Set numeric quality from 0 to 100 (Default: 75)
$ratio = $optimizer
    ->withQuality(60)
    ->optimize('input.jpg', 'output.jpg');
```

### Image Configuration
- **Supported Formats**: Input and output can be `.jpg`, `.jpeg`, or `.png`.
- **Quality**: Accepts an integer between `0` (maximum compression) and `100` (best quality).
- **Auto-Dimensioning**: The library automatically detects the original image dimensions and configures Ghostscript to match them exactly, preventing the common "white canvas" border issue.


### Additional Flags
Configure PDF compatibility or pass raw Ghostscript arguments:

```php
$optimizer
    ->withCompatibilityLevel('1.5')
    ->withExtraArgs(
        '-dColorConversionStrategy=/sRGB',
        '-dProcessColorModel=/DeviceRGB'
    )
    ->optimize('in.pdf', 'out.pdf');
```

### Error Handling
The library throws `GomdimApps\Slimmer\Exceptions\SlimmerException` for all errors (file not found, engine failure, etc.).

```php
try {
    $optimizer->optimize('in.pdf', 'out.pdf');
} catch (\GomdimApps\Slimmer\Exceptions\SlimmerException $e) {
    // Handle error (e.g., log $e->getMessage())
}
```

## Docker Testing

Run the test suite in an isolated environment:

```bash
make test
```

## License

MIT
