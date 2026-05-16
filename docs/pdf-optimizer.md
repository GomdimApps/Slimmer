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

## Extra Ghostscript Arguments

Pass arbitrary raw flags directly to `gs`:

```php
$optimizer
    ->withExtraArgs(
        '-dColorConversionStrategy=/sRGB',
        '-dProcessColorModel=/DeviceRGB'
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
