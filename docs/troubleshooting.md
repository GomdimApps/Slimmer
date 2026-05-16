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
