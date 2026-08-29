# Installation & Requirements

## Requirements

| Requirement | Version |
|---|---|
| **PHP** | >= 8.2 |
| **PHP Extension** | `ext-gd` |
| **Ghostscript** (`gs`) | Any recent version |
| **tar** | System binary (for Tar compression/extraction) |
| **zstd** | Required only for `.tar.zst` archives |
| **bzip2** | Required only for `.tar.bz2` archives |
| **zip** / **unzip** | Required only for Zip compression/extraction |

## Install via Composer

```bash
composer require gomdim-apps/slimmer
```

## Verifying External Binaries

Check that Ghostscript is available:

```bash
gs --version
# e.g. 9.56.1
```

Check that tar is available:

```bash
tar --version | head -1
# e.g. tar (GNU tar) 1.34
```

Check that zstd is available (`.tar.zst` only):

```bash
zstd --version
# e.g. zstd command line interface 64-bits v1.5.5
```
