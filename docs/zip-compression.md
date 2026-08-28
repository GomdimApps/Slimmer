# Zip Compression

`CompressZip` compresses files and directories into `.zip` archives. It wraps the system `zip` binary and returns a `float` representing the ratio of size reduction.

`ExtractZip` reads them back — see [Extracting Archives](#extracting-archives) and [Listing Archive Contents](#listing-archive-contents) below.

Unlike Tar (a single `tar` binary handles create/extract/list), Zip's create path (`zip`) and read path (`unzip`) are separate binaries — each is resolved lazily, only when an operation that needs it is actually called.

## Basic Usage

```php
use GomdimApps\Slimmer\Optimizers\CompressZip;

$optimizer = new CompressZip();

// Returns float, e.g. 0.62 = 62% reduction
$ratio = $optimizer->optimize('/path/to/dir', '/path/to/output.zip');
```

When `$outputPath` does not end with `.zip`, a timestamped filename is generated automatically, exactly like `CompressTar`:

```
/path/to/output/dir_2026-05-16_143000.zip
```

## Compression Level

```php
$optimizer->withCompressionLevel(9); // 0 (store, no compression) to 9 (max). Default: 6
```

**Note**: `0` is a valid level for Zip (stored, uncompressed) — this differs from Tar's gz/bz2 formats, whose floor is `1`. Out-of-range values throw `\InvalidArgumentException`.

## Exclude Patterns

Patterns are cumulative across calls and forwarded as `-x <pattern>` to `zip` (create) or `unzip` (extract):

```php
$optimizer
    ->withExclude('*.log', '*.tmp')
    ->withExclude('node_modules/*');
```

## Custom Arguments

Append raw CLI arguments directly to the `zip` command:

```php
$optimizer->withCustomArgs('--symlinks');
```

## Progress Callback

Receive one filename per line of `adding:` output as files are added to the archive:

```php
$optimizer
    ->withProgress(fn (string $line) => fwrite(STDERR, "{$line}\n"))
    ->optimize('/path/to/dir', '/output/archive.zip');
```

Leaving it unset (the default) keeps the generated `zip` command byte-identical to a call without it.

## Dry Run

Returns the exact command string without executing it:

```php
$command = $optimizer
    ->withCompressionLevel(9)
    ->dryRun('/path/to/dir', '/path/to/output/');

echo $command;
// zip -r -9 /path/to/output/dir_....zip dir
```

## Compress and Retain

Compress a source, **delete the source**, and keep only the `$limit` most-recent `.zip` archives in the output directory (oldest are deleted first) — same semantics as `CompressTar::compressAndRetain()`:

```php
$ratio = $optimizer->compressAndRetain('/path/to/dir', '/backups/', 5);
```

## Clean Directory

```php
// Keep only the 10 most-recent .zip files in /backups/
$optimizer->cleanDirectory('/backups/', 10);
```

## Custom zip/unzip Binaries

```php
use GomdimApps\Slimmer\Engines\ZipEngine;
use GomdimApps\Slimmer\Optimizers\CompressZip;

$engine    = new ZipEngine('/usr/local/bin/zip', '/usr/local/bin/unzip');
$optimizer = new CompressZip($engine);
```

## Extracting Archives

`ExtractZip` reads existing `.zip` archives back — kept as a separate class from `CompressZip`, mirroring the Tar/`ExtractTar` split, since extraction has no size-reduction ratio to report:

```php
use GomdimApps\Slimmer\Optimizers\ExtractZip;

$extracted = (new ExtractZip())->extract('/path/to/archive.zip', '/path/to/output-dir');
// string[] — absolute paths of every extracted file
```

### Exclude Patterns on Extraction

```php
(new ExtractZip())->withExclude('*.log')->extract('/path/to/archive.zip', '/output-dir');
```

### Progress Callback

```php
(new ExtractZip())
    ->withProgress(fn (string $line) => fwrite(STDERR, "{$line}\n"))
    ->extract('/path/to/archive.zip', '/output-dir');
```

### Dry Run

```php
$command = (new ExtractZip())->dryRun('/path/to/archive.zip', '/output-dir');
// unzip -o -q /path/to/archive.zip -d /output-dir
```

`dryRun()` performs no filesystem writes — the output directory is not created.

## Listing Archive Contents

```php
$paths = (new ExtractZip())->listContents('/path/to/archive.zip');
// string[] — one member path per archive entry, via `unzip -Z1`
```

Like Tar's `listContents()`, this is intentionally a plain list of paths, not a rich per-entry metadata object — kept symmetric with the Tar side for the same fragility-vs-value reasons.

## Password / Encryption

Not supported. `zip -P <password>` leaks the password via the process argument list (visible to any co-located user via `/proc/<pid>/cmdline`), and `zip -e` requires an interactive TTY prompt incompatible with non-interactive subprocess execution. If you need encrypted archives, encrypt the resulting `.zip` file separately.

## Streams & Buffers

`CompressZip` and `ExtractZip` both support in-memory input via `fromString()`/`fromStream()`, same as every other optimizer:

```php
$ratio = (new CompressZip())->fromString($content)->optimize(null, '/output/archive.zip');
```

## Timeout

```php
$engine = new ZipEngine();
$engine->setTimeout(30.0); // 30-second limit; 0 = no timeout (default)
```
