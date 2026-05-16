# Error Handling

All exceptions thrown by Slimmer extend `SlimmerException`, so you can catch them at any granularity.

## Exception Hierarchy

```
\Exception
  └── SlimmerException          (GomdimApps\Slimmer\Exceptions\SlimmerException)
        └── TarException        (GomdimApps\Slimmer\Exceptions\TarException)
```

## SlimmerException

Thrown for general errors:

- Binary not found (e.g. `gs` or `tar` not in `PATH`)
- Input file or path not found
- Output directory not writable
- Engine execution failure

```php
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

try {
    $ratio = (new PdfOptimizer())->optimize('in.pdf', 'out.pdf');
} catch (SlimmerException $e) {
    echo $e->getMessage();
}
```

## TarException

Extends `SlimmerException`. Thrown for tar-specific failures:

- Compression command failed
- Unsupported archive format
- Source deletion failure (in `compressAndRetain`)
- Retention cleanup failure (in `cleanDirectory`)

```php
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\TarException;
use GomdimApps\Slimmer\Optimizers\CompressTar;

try {
    $ratio = (new CompressTar())->optimize('/path/to/dir', '/output/');
} catch (TarException $e) {
    // tar-specific error
    echo 'Tar error: ' . $e->getMessage();
} catch (SlimmerException $e) {
    // binary not found, path issues, etc.
    echo 'Slimmer error: ' . $e->getMessage();
}
```
