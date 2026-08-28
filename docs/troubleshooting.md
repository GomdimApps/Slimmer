# Troubleshooting

## PHP Permission Failures (`proc_open`)

If you encounter permission denied errors or failures when PHP tries to execute the `gs` binary:

### 1. Execution Permissions

Ensure the PHP process user (e.g. `www-data` or `php-fpm`) has execute permissions on the binary:

```bash
chmod +x /path/to/bin/gs
```

### 2. `open_basedir` Restrictions

Check your `php.ini` to ensure the directory containing the `gs` binary is allowed:

```ini
; php.ini
open_basedir = /usr/bin:/tmp:/var/www
```

Add the directory containing `gs` if it is missing.

### 3. SELinux / AppArmor

Security modules like SELinux or AppArmor may restrict PHP from executing external binaries.
You may need to adjust policies to allow the PHP process to run `gs`.

### 4. Disabled Functions

Ensure these functions are **not** in the `disable_functions` directive in your `php.ini`:

```ini
; php.ini — these must NOT be disabled
; proc_open, proc_close, proc_get_status, proc_terminate
```

## Ghostscript Not Found

If `SlimmerException` is thrown with a message like "binary not found", confirm `gs` is in your `PATH`:

```bash
which gs
# /usr/bin/gs
```

Or provide the full path when constructing the engine:

```php
use GomdimApps\Slimmer\Engines\GhostscriptEngine;

$engine = new GhostscriptEngine('/usr/local/bin/gs');
```

## zstd Not Found

`.tar.zst` archives require `zstd` to be installed:

```bash
# Debian / Ubuntu
sudo apt install zstd

# macOS (Homebrew)
brew install zstd
```

## Process Timeout

If compression of a large file exceeds the configured timeout, a `SlimmerException` is thrown. Increase or remove the limit:

```php
$engine->setTimeout(120.0); // 2-minute limit
// or
$engine->setTimeout(0);     // no timeout
```

## Pages Rotated Sideways After Compression

Ghostscript's default page auto-rotation heuristic can occasionally rotate image-only or diagram-only pages sideways or upside-down during compression, even though the source PDF looks correct. Pin rotation off explicitly:

```php
$optimizer
    ->withAutoRotatePages() // defaults to 'None'
    ->optimize('input.pdf', 'output.pdf');
```

See [PDF Optimizer → Auto-Rotate Pages](pdf-optimizer.md#auto-rotate-pages) for details.

## Object Streams Have No Effect

`withObjectStreamCompression()` (or `withAggressiveCompression()`, which enables it) requires a PDF compatibility level of **1.5 or higher**. If you call `withCompatibilityLevel()` again afterward and drop back below `1.5`, Ghostscript silently ignores the object/cross-reference stream flags instead of erroring — the output PDF is still valid, it just won't get that particular size reduction. Keep the compatibility level at `1.5`+ for the whole chain:

```php
$optimizer
    ->withCompatibilityLevel('1.5')
    ->withObjectStreamCompression()
    ->optimize('input.pdf', 'output.pdf');
```

See [PDF Optimizer → Object & Cross-Reference Stream Compression](pdf-optimizer.md#object--cross-reference-stream-compression).

## Slow Compression on Large / Image-Heavy PDFs

For PDFs with many large embedded images, Ghostscript can hit internal memory limits and slow down or fail. Raise the relevant buffers via `withExtraArgs()`:

```php
$optimizer
    ->withExtraArgs('-dBufferSpace=1000000000', '-dMaxBitmap=1000000000')
    ->optimize('input.pdf', 'output.pdf');
```

Also consider `withStreamEffort()` at a lower value (1-3) to trade some size for a faster pass — see [PDF Optimizer → Stream Compression Effort](pdf-optimizer.md#stream-compression-effort).
