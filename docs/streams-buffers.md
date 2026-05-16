# Streams & Buffers

All optimizers (`PdfOptimizer`, `ImageOptimizer`, `CompressTar`) support in-memory input via `fromString()` and `fromStream()`. The library automatically manages any temporary files required by the underlying engine.

When using in-memory input, pass `null` as the first argument to `optimize()`.

## From a String

```php
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

$pdfContent = file_get_contents('document/sample.pdf');

$ratio = (new PdfOptimizer())
    ->fromString($pdfContent)
    ->optimize(null, 'output.pdf');
```

## From a Stream

```php
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

$stream = fopen('document/sample.pdf', 'rb');

$ratio = (new PdfOptimizer())
    ->fromStream($stream)
    ->optimize(null, 'output.pdf');

fclose($stream);
```

## Works with All Optimizers

The same API applies to `ImageOptimizer` and `CompressTar`:

```php
use GomdimApps\Slimmer\Optimizers\ImageOptimizer;

$imageContent = file_get_contents('photo.jpg');

$ratio = (new ImageOptimizer())
    ->withQuality(70)
    ->fromString($imageContent)
    ->optimize(null, 'photo-compressed.jpg');
```

```php
use GomdimApps\Slimmer\Optimizers\CompressTar;

$stream = fopen('/path/to/file.log', 'rb');

$ratio = (new CompressTar())
    ->fromStream($stream)
    ->optimize(null, '/backups/archive.tar.gz');

fclose($stream);
```
