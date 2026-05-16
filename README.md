# Slimmer

A PHP library for advanced file compression. Currently focused on high-quality PDF optimization and image compression using Ghostscript.

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

// Check Ghostscript version
echo $engine->getVersion(); // e.g. "9.54.0"
```

### Process Timeout
Set a maximum execution time (in seconds, supports decimals) to prevent Ghostscript from hanging indefinitely:

```php
$engine->setTimeout(0.5); // 500ms limit
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
- **Auto-Dimensioning**: The library automatically detects the original image dimensions.
- **Custom Dimensions**: Manually force specific dimensions:
  ```php
  $optimizer->withDimensions(800, 600)->optimize('in.jpg', 'out.jpg');
  ```

## Streams & Buffers (In-Memory Optimization)

Slimmer can handle input directly from memory or streams, managing the temporary files required by Ghostscript automatically.

```php
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

$optimizer = new PdfOptimizer();

// From a String
$pdfContent = file_get_contents('document/sample.pdf');
$ratio = $optimizer->fromString($pdfContent)->optimize(null, 'output.pdf');

// From a Stream
$stream = fopen('document/image.jpg', 'rb');
$ratio = $optimizer->fromStream($stream)->optimize(null, 'output.pdf');
fclose($stream);
```

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

### Dry Run (Command Inspection)
Get the exact Ghostscript command string without executing it:

```php
$command = $optimizer
    ->withQuality('screen')
    ->dryRun('input.pdf', 'output.pdf');

echo $command; // "gs -sDEVICE=pdfwrite ..."
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

## Troubleshooting

### PHP Permission Failures (proc_open)

If you encounter permission denied errors or failures when PHP attempts to execute the Ghostscript (`gs`) binary (especially if installed locally in a `bin` folder or similar), check the following:

1. **Execution Permissions**: Ensure the PHP process user (e.g., `www-data` or `php-fpm`) has execute permissions on the Ghostscript binary:
   ```bash
   chmod +x /path/to/bin/gs
   ```
2. **open_basedir Restrictions**: Check your `php.ini` configuration to ensure that the directory containing the Ghostscript binary is allowed by the `open_basedir` directive.
3. **SELinux / AppArmor**: Security modules like SELinux or AppArmor might restrict PHP from executing external binaries. You may need to configure policies to allow the PHP process to run `gs`.
4. **Disabled Functions**: Ensure that `proc_open`, `proc_close`, `proc_get_status`, and `proc_terminate` are not restricted in the `disable_functions` directive in your `php.ini`.

## Docker Testing

Run the test suite in an isolated environment:

```bash
make test
```

## License

MIT
