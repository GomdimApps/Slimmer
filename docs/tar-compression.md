# Tar Compression

`CompressTar` compresses files and directories into `.tar.gz` or `.tar.zst` archives. It wraps the system `tar` binary and returns a `float` representing the ratio of size reduction.

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
```

## Compression Level

```php
$optimizer->withCompressionLevel(9); // 1–9 for gz, 1–19 for zst (default: 6)
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
