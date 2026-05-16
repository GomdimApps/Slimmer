# Image Optimizer

`ImageOptimizer` compresses JPG and PNG images while preserving their original dimensions. It uses PHP's built-in `ext-gd` extension — no external binary required.

## Basic Usage

```php
use GomdimApps\Slimmer\Optimizers\ImageOptimizer;

$optimizer = new ImageOptimizer();

// Returns float, e.g. 0.35 = 35% reduction
$ratio = $optimizer
    ->withQuality(60)
    ->optimize('input.jpg', 'output.jpg');
```

## Supported Formats

| Input | Output |
|---|---|
| `.jpg` / `.jpeg` | `.jpg` / `.jpeg` |
| `.png` | `.png` |

> Cross-format conversion (e.g. PNG → JPG) is **not** supported. Input and output formats must match.

## Quality

Accepts an integer between `0` and `100`:

| Value | Effect |
|---|---|
| `0` | Maximum compression, lowest quality |
| `75` | Default |
| `100` | Best quality, no compression |

```php
$optimizer->withQuality(75); // default
```

## Dimensions

### Auto-detection (default)

The library reads the original image dimensions automatically — no extra configuration needed.

### Custom Dimensions

Force specific output dimensions:

```php
$optimizer
    ->withDimensions(800, 600)
    ->optimize('input.png', 'output.png');
```
