# Slimmer

A PHP library for advanced file compression. Supports high-quality PDF optimization and image compression via Ghostscript, and directory/file archiving via tar (.tar.gz / .tar.zst).


## Requirements

- **PHP**: >= 8.2
- **Extensions**: `ext-gd`
- **External**: [Ghostscript](https://www.ghostscript.com/) (`gs`) must be installed.
- **External** *(tar compression)*: `tar` must be installed. For `.tar.zst` archives, `zstd` must also be installed.


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

## Tar Compression

Compress files and directories into `.tar.gz` or `.tar.zst` archives via `CompressTar`.

```php
use GomdimApps\Slimmer\Optimizers\CompressTar;

$optimizer = new CompressTar();

// Returns float ratio of reduction (e.g., 0.62 = 62% reduction)
$ratio = $optimizer->optimize('/path/to/dir', '/path/to/output.tar.gz');
```

When `$outputPath` does not end with the archive extension, a timestamped filename is generated automatically:

```
/path/to/output/dir_2026-05-16_143000.tar.gz
```

### Format

```php
$optimizer->withFormat('gz');   // .tar.gz via gzip  (default)
$optimizer->withFormat('zst');  // .tar.zst via zstd
```

### Compression Level

```php
$optimizer->withCompressionLevel(9); // 1–9 for gz, 1–19 for zst (default: 6)
```

### Parallel Threads (zst only)

```php
$optimizer->withThreads(4); // number of CPU threads (default: 1)
```

### Exclude Patterns

Patterns are cumulative across calls. Forwarded as `--exclude=<pattern>` to tar.

```php
$optimizer
    ->withExclude('*.log', '*.tmp')
    ->withExclude('node_modules');
```

### Ignore Empty Directories

Omit directories that contain no files from the archive.

```php
$optimizer->ignoreEmptyDirectories();
```

### Preserve Permissions

Adds the `-p` flag so file permissions are stored in the archive.

```php
$optimizer->preservePermissions();
```

### Custom Arguments

Append raw CLI arguments directly to the tar command.

```php
$optimizer->withCustomArgs('--verbose');
```

### Dry Run

Returns the exact command string without executing it.

```php
$command = $optimizer
    ->withFormat('zst')
    ->withCompressionLevel(9)
    ->dryRun('/path/to/dir', '/path/to/output/');

echo $command;
// /bin/tar --use-compress-program="zstd -9 -T1" -cf /path/to/output/dir_....tar.zst -C /path/to dir
```

### Streams & Buffers

`CompressTar` implements the `Optimizer` contract and supports in-memory input:

```php
// From a string
$ratio = $optimizer->fromString($content)->optimize(null, '/output/archive.tar.gz');

// From a stream
$stream = fopen('/path/to/file', 'rb');
$ratio  = $optimizer->fromStream($stream)->optimize(null, '/output/archive.tar.gz');
fclose($stream);
```

### Compress and Retain

Compress a source, **delete the source**, then keep only the `$limit` most-recent archives in the output directory (oldest ones are deleted).

```php
// Produces archive, removes /path/to/dir, keeps 5 most-recent archives in /backups/
$ratio = $optimizer->compressAndRetain('/path/to/dir', '/backups/', 5);
```

### Clean Directory

Remove archives beyond a retention limit from a directory. Archives are sorted by modification time; the oldest are deleted.

```php
// Keep only the 10 most-recent .tar.gz / .tar.zst files in /backups/
$optimizer->cleanDirectory('/backups/', 10);
```

Both `.tar.gz` and `.tar.zst` files are considered together when counting. Passing `0` removes all archives.

### Custom tar Binary

```php
use GomdimApps\Slimmer\Engines\TarEngine;
use GomdimApps\Slimmer\Optimizers\CompressTar;

$engine    = new TarEngine('/usr/local/bin/tar');
$optimizer = new CompressTar($engine);
```

You can also interact with `TarEngine` directly if you only need subprocess control:

```php
$engine = new TarEngine();

echo $engine->getVersion(); // version string reported by the binary

$engine
    ->withCompressionLevel(9)
    ->withThreads(4)
    ->withExclude('*.log')
    ->withIgnoreEmptyDirectories()
    ->compress('/path/to/dir', '/output/archive.tar.zst', 'zst');
```

`TarEngine::buildArgv()` is also public and useful for inspecting or extending the generated command without executing it.

### Timeout

Applies to both `TarEngine` and indirectly to `CompressTar` via the injected engine.

```php
$engine = new TarEngine();
$engine->setTimeout(30.0); // 30-second limit; 0 = no timeout (default)

$optimizer = new CompressTar($engine);
```

### Error Handling

Binary resolution failures throw `GomdimApps\Slimmer\Exceptions\SlimmerException`.  
All tar-specific errors (compression failure, unsupported format, deletion failure, retention cleanup failure) throw `GomdimApps\Slimmer\Exceptions\TarException`, which extends `SlimmerException`.

```php
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\TarException;

try {
    $optimizer->optimize('/path/to/dir', '/output/');
} catch (TarException $e) {
    // tar-specific error (compression failed, unsupported format, etc.)
} catch (SlimmerException $e) {
    // binary not found, input path not found, output directory not writable
}
```

## Docker Testing

Run the test suite in an isolated environment:

```bash
make test
```

## License

MIT
