<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers\Utils\Tar;

/**
 * Detects a tar archive's compression format from magic bytes or, failing
 * that, its file extension. Mirrors the detection strategy used by
 * splitbrain/php-archive's Tar::filetype().
 */
class TarFormatDetector
{
    /**
     * @return 'gz'|'zst'|'bz2'|null Null when the format cannot be determined.
     */
    public static function detect(string $path): ?string
    {
        $byMagic = self::detectByMagicBytes($path);
        if ($byMagic !== null) {
            return $byMagic;
        }

        return self::detectByExtension($path);
    }

    private static function detectByMagicBytes(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $magic = fread($handle, 4);
        fclose($handle);

        if ($magic === false || $magic === '') {
            return null;
        }

        return match (true) {
            str_starts_with($magic, "\x1f\x8b") => 'gz',
            str_starts_with($magic, 'BZh') => 'bz2',
            str_starts_with($magic, "\x28\xb5\x2f\xfd") => 'zst',
            default => null,
        };
    }

    private static function detectByExtension(string $path): ?string
    {
        $path = strtolower($path);

        return match (true) {
            str_ends_with($path, '.tar.gz'), str_ends_with($path, '.tgz') => 'gz',
            str_ends_with($path, '.tar.bz2'), str_ends_with($path, '.tbz'), str_ends_with($path, '.tbz2') => 'bz2',
            str_ends_with($path, '.tar.zst'), str_ends_with($path, '.tzst') => 'zst',
            default => null,
        };
    }
}
