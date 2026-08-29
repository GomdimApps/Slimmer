# PDF Optimizer

`PdfOptimizer` compresses PDF files using [Ghostscript](https://www.ghostscript.com/). It returns a `float` representing the ratio of size reduction.

## Basic Usage

```php
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

$optimizer = new PdfOptimizer();

// Returns float, e.g. 0.4523 = 45.23% reduction
$ratio = $optimizer
    ->withQuality('screen')
    ->optimize('input.pdf', 'output.pdf');
```

## Quality Presets

Pass a preset string to `withQuality()`:

| Preset | DPI | Description |
|---|---|---|
| `screen` | 72 | Smallest size, lowest quality. Best for web. |
| `ebook` | 150 | Balanced quality and size. **(Default)** |
| `printer` | 300 | High quality for printing. |
| `prepress` | 300 | Maximum quality, color-preserving. |
| `default` | — | System default (usually equivalent to `printer`). |

## Custom Ghostscript Binary

If `gs` is not in your `PATH` or you need a specific version:

```php
use GomdimApps\Slimmer\Engines\GhostscriptEngine;
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

$engine    = new GhostscriptEngine('/usr/local/bin/gs');
$optimizer = new PdfOptimizer($engine);

echo $engine->getVersion(); // e.g. "9.54.0"
```

## Process Timeout

Prevent Ghostscript from hanging indefinitely by setting a timeout in seconds (decimals supported):

```php
$engine = new GhostscriptEngine();
$engine->setTimeout(0.5); // 500 ms limit

$optimizer = new PdfOptimizer($engine);
```

## PDF Compatibility Level

Force a specific PDF compatibility version:

```php
$optimizer
    ->withCompatibilityLevel('1.5')
    ->optimize('input.pdf', 'output.pdf');
```

## Duplicate Image Detection

Deduplicate identical embedded images (e.g. a logo or letterhead repeated on every page) with **zero quality loss**:

```php
$optimizer
    ->withDetectDuplicateImages()
    ->optimize('input.pdf', 'output.pdf');
```

Ghostscript's own default for this varies by build, so calling this explicitly guarantees the behavior regardless of the installed `gs` version. Pass `false` to force it off.

## Image Downsampling

Override the DPI, resampling algorithm, and threshold used for **Color and Gray** images, independent of the `withQuality()` preset:

```php
$optimizer
    ->withImageDownsampling(dpi: 120, type: 'Bicubic', threshold: 1.5)
    ->optimize('input.pdf', 'output.pdf');
```

- `$dpi` — target resolution for color and gray images.
- `$type` — `Subsample` (fastest, lowest quality per DPI), `Average`, or `Bicubic` (best quality per DPI, default here).
- `$threshold` — how much larger than the target (as a ratio) an image must be before it gets downsampled at all (Ghostscript default: `1.5`).

Mono images are intentionally left untouched — they're usually scanned text or line art, where cutting DPI hurts legibility. For per-channel control beyond this (including Mono), use `withExtraArgs()` directly.

## Object & Cross-Reference Stream Compression

Compress the PDF's internal object table using compressed object streams and cross-reference streams (`-dWriteObjStms` / `-dWriteXRefStm`) — a real, well-supported size win (PDF 1.5+, i.e. Acrobat 6/2003 onward) especially on PDFs with many annotations or bookmarks. This **requires a compatibility level of 1.5 or higher**:

```php
$optimizer
    ->withCompatibilityLevel('1.5')   // must be called first (or already >= 1.5)
    ->withObjectStreamCompression()
    ->optimize('input.pdf', 'output.pdf');
```

Calling `withObjectStreamCompression()` while the compatibility level is still below `1.5` throws `\InvalidArgumentException`. Conversely, if you call `withCompatibilityLevel()` again *after* enabling this and drop back below `1.5`, Ghostscript will silently ignore the object-stream flags rather than erroring — keep the compat-level call before (or at) `1.5` for this to take effect.

## Stream Compression Effort

Tune the CPU effort Ghostscript spends compressing fonts and content streams (`-dStreamEffort`), trading time for size:

```php
$optimizer
    ->withStreamEffort(9) // 1 = fastest/largest, 9 = slowest/smallest, gs default is 5
    ->optimize('input.pdf', 'output.pdf');
```

This is the main lever for **compression time** with the `pdfwrite` device — Ghostscript's multi-threaded rendering (`-dNumRenderingThreads`) does not apply here, since `pdfwrite` is a vector device and doesn't render to a bitmap.

## Fast Web View (Linearization)

Optimize the PDF for progressive rendering while it's still downloading over a network (`-dFastWebView`):

```php
$optimizer
    ->withFastWebView()
    ->optimize('input.pdf', 'output.pdf');
```

This targets network delivery, **not** file size — linearized output can be slightly larger than the non-linearized version. Use it for PDFs served over the web, not as a size-reduction tool.

## Auto-Rotate Pages

Ghostscript's default page auto-rotation heuristic (`/PageByPage`) can occasionally rotate image-only or diagram-only pages sideways or upside-down. Pin it explicitly to avoid that:

```php
$optimizer
    ->withAutoRotatePages() // defaults to 'None'
    ->optimize('input.pdf', 'output.pdf');
```

Accepts `None` (default), `All`, or `PageByPage`. See [Troubleshooting](troubleshooting.md) if you're hitting this issue on existing output.

## Aggressive Compression Preset

`withAggressiveCompression()` bundles the **lossless/structural** wins above — object & cross-reference stream compression (bumping the compatibility level to `1.5` if it's lower), duplicate image detection, maximum stream effort, and safe page auto-rotation:

```php
$optimizer
    ->withAggressiveCompression()
    ->optimize('input.pdf', 'output.pdf');
```

It deliberately does **not** touch `quality`/DPI, so it never trades away visual fidelity on its own. Combine it with `withQuality('screen')` or `withImageDownsampling()` for lossy size reduction on top:

```php
$optimizer
    ->withQuality('screen')
    ->withAggressiveCompression()
    ->optimize('input.pdf', 'output.pdf');
```

## Extra Ghostscript Arguments

Pass arbitrary raw flags directly to `gs`. Anything passed here is applied **after** every option above, so it always wins over the library's built-in flags of the same name:

```php
$optimizer
    ->withExtraArgs(
        '-dColorConversionStrategy=/sRGB',
        '-dProcessColorModel=/DeviceRGB'
    )
    ->optimize('input.pdf', 'output.pdf');
```

### Advanced Recipes

A couple of powerful `gs` options are intentionally **not** first-class methods, because they carry sharper compatibility trade-offs — use them via `withExtraArgs()` only when you understand the risk:

**Brotli stream compression** — better compression ratio than Flate, but requires a PDF 2.0 reader. Support for PDF 2.0 is not universal across PDF viewers, so never enable this for output you can't control the reader for:

```php
$optimizer
    ->withCompatibilityLevel('2.0')
    ->withExtraArgs('-dUseBrotli=true')
    ->optimize('input.pdf', 'output.pdf');
```

**JPEG re-encode quality (`/QFactor`)** — fine-tunes the JPEG quality Ghostscript uses when re-encoding images, via a PostScript distiller-params snippet. Note the required trailing `'-f'` element — without it, Ghostscript treats the auto-appended input path as more PostScript instead of a file to read:

```php
$optimizer
    ->withExtraArgs(
        '-c', '<< /ColorImageDict << /QFactor 0.76 >> >> setdistillerparams',
        '-f'
    )
    ->optimize('input.pdf', 'output.pdf');
```

## Dry Run

Inspect the full Ghostscript command without executing it:

```php
$command = $optimizer
    ->withQuality('screen')
    ->dryRun('input.pdf', 'output.pdf');

echo $command;
// gs -sDEVICE=pdfwrite ...
```
