# Tar Compression

`CompressTar` compresses files and directories into `.tar.gz`, `.tar.zst` or `.tar.bz2` archives. It wraps the system `tar` binary and returns a `float` representing the ratio of size reduction.

To read archives back, see [Extracting Archives](#extracting-archives) and [Listing Archive Contents](#listing-archive-contents) below — those use the separate `ExtractTar` class.

## Basic Usage

```php
use GomdimApps\Slimmer\Optimizers\CompressTar;

$optimizer = new CompressTar();

// Returns float, e.g. 0.62 = 62% reduction
$ratio = $optimizer->optimize('/path/to/dir', '/path/to/output.tar.gz');
```

When `$outputPath` does not end with the archive extension, a timestamped filename is generated automatically:

```
/path/to/output/dir_2026-05-16_143000.tar.gz
```

## Format

```php
$optimizer->withFormat('gz');   // .tar.gz via gzip (default)
$optimizer->withFormat('zst');  // .tar.zst via zstd
$optimizer->withFormat('bz2');  // .tar.bz2 via bzip2
```

## Compression Level

```php
$optimizer->withCompressionLevel(9); // default: 6
```

Validated against the current format's range and throws `\InvalidArgumentException` when out of bounds:

| Format | Range |
|---|---|
| `gz`  | 1–9 |
| `zst` | 1–19 |
| `bz2` | 1–9 |

Since format and level can be set in either order, both `withFormat()` and `withCompressionLevel()` validate against
whichever of the two is currently known:

```php
$optimizer->withCompressionLevel(15); // throws: 15 is out of range for the default 'gz' format
$optimizer->withFormat('zst')->withCompressionLevel(15); // OK — valid for zst
```

## Parallel Threads (zst only)

```php
$optimizer->withThreads(4); // number of CPU threads (default: 1)
```

## Exclude Patterns

Patterns are cumulative across calls and forwarded as `--exclude=<pattern>` to `tar`:

```php
$optimizer
    ->withExclude('*.log', '*.tmp')
    ->withExclude('node_modules');
```

## Ignore Empty Directories

Omit directories that contain no files from the archive:

```php
$optimizer->ignoreEmptyDirectories();
```

## Preserve Permissions

Adds the `-p` flag so file permissions are stored in the archive:

```php
$optimizer->preservePermissions();
```

## Custom Arguments

Append raw CLI arguments directly to the `tar` command:

```php
$optimizer->withCustomArgs('--verbose');
```

## Dry Run

Returns the exact command string without executing it:

```php
$command = $optimizer
    ->withFormat('zst')
    ->withCompressionLevel(9)
    ->dryRun('/path/to/dir', '/path/to/output/');

echo $command;
// /bin/tar --use-compress-program="zstd -9 -T1" -cf /path/to/output/dir_....tar.zst -C /path/to dir
```

## Compress and Retain

Compress a source, **delete the source**, and keep only the `$limit` most-recent archives in the output directory (oldest are deleted first):

```php
// Produces archive, removes /path/to/dir, keeps 5 most-recent archives in /backups/
$ratio = $optimizer->compressAndRetain('/path/to/dir', '/backups/', 5);
```

## Clean Directory

Remove archives beyond a retention limit from a directory. Archives are sorted by modification time; the oldest are deleted:

```php
// Keep only the 10 most-recent .tar.gz / .tar.zst files in /backups/
$optimizer->cleanDirectory('/backups/', 10);
```

Both `.tar.gz` and `.tar.zst` files are counted together. Passing `0` removes all archives.

## Progress Callback

Receive one filename per line of `tar -v` output as files are added to the archive:

```php
$optimizer
    ->withProgress(fn (string $line) => fwrite(STDERR, "added: {$line}\n"))
    ->optimize('/path/to/dir', '/output/archive.tar.gz');
```

Leaving it unset (the default) keeps the generated `tar` command byte-identical to a call without it.

## Extracting Archives

`ExtractTar` reads existing archives back — kept as a separate class from `CompressTar` since extraction has no
size-reduction ratio to report:

```php
use GomdimApps\Slimmer\Optimizers\ExtractTar;

$extracted = (new ExtractTar())->extract('/path/to/archive.tar.gz', '/path/to/output-dir');
// string[] — absolute paths of every extracted file
```

The format is auto-detected from the archive's magic bytes, falling back to its file extension. Pass `withFormat()`
to skip detection:

```php
(new ExtractTar())->withFormat('bz2')->extract('/path/to/archive', '/output-dir');
```

### Strip Path Components

Mirrors GNU tar's `--strip-components`:

```php
// archive.tar.gz contains "myproject/src/App.php" -> extracted as "src/App.php"
(new ExtractTar())->withStripComponents(1)->extract('/path/to/archive.tar.gz', '/output-dir');
```

### Exclude Patterns on Extraction

```php
(new ExtractTar())->withExclude('*.log')->extract('/path/to/archive.tar.gz', '/output-dir');
```

Selecting only specific members to extract (include patterns) is not yet supported — extract the full archive and
filter the returned paths, or use `withExtraArgs`-style raw member arguments via a custom `TarEngine` call.

### Progress Callback

```php
(new ExtractTar())
    ->withProgress(fn (string $line) => fwrite(STDERR, "extracted: {$line}\n"))
    ->extract('/path/to/archive.tar.gz', '/output-dir');
```

### Dry Run

```php
$command = (new ExtractTar())->dryRun('/path/to/archive.tar.gz', '/output-dir');
// tar --use-compress-program=gzip -xf /path/to/archive.tar.gz -C /output-dir
```

`dryRun()` performs no filesystem writes — the output directory is not created.

## Listing Archive Contents

List an archive's member paths without extracting it:

```php
$paths = (new ExtractTar())->listContents('/path/to/archive.tar.gz');
// string[] — one member path per archive entry
```

This is intentionally a plain list of paths, not a rich per-entry metadata object (size/mtime/permissions) — GNU
tar's verbose listing format is comparatively fragile to parse reliably across tar versions and locales. If you need
that metadata, `TarEngine::buildListArgv()` gives you the base command to extend with `-v` yourself.

## Custom tar Binary

```php
use GomdimApps\Slimmer\Engines\TarEngine;
use GomdimApps\Slimmer\Optimizers\CompressTar;

$engine    = new TarEngine('/usr/local/bin/tar');
$optimizer = new CompressTar($engine);
```

## TarEngine Direct Usage

You can interact with `TarEngine` directly if you only need subprocess control:

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

## Timeout

Applies to both `TarEngine` and `CompressTar` via the injected engine:

```php
$engine = new TarEngine();
$engine->setTimeout(30.0); // 30-second limit; 0 = no timeout (default)

$optimizer = new CompressTar($engine);
```

## Streams & Buffers

`CompressTar` implements the `Optimizer` contract and supports in-memory input:

```php
// From a string
$ratio = $optimizer->fromString($content)->optimize(null, '/output/archive.tar.gz');

// From a stream
$stream = fopen('/path/to/file', 'rb');
$ratio  = $optimizer->fromStream($stream)->optimize(null, '/output/archive.tar.gz');
fclose($stream);
```
