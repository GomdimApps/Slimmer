<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Support;

/**
 * Shared compression-level range validation, used by both the Tar and Zip
 * engines/optimizers to avoid re-deriving the same range-check-and-throw
 * logic in four places.
 */
final class CompressionLevelValidator
{
    /**
     * @param array{0: int, 1: int} $range   [min, max] allowed levels.
     * @param string|null           $format  When given, produces the Tar-style message
     *                                       ("...for format \"X\"..."); when null, produces
     *                                       the Zip-style message ("...for zip...").
     *
     * @throws \InvalidArgumentException
     */
    public static function assertInRange(int $level, array $range, ?string $format = null): void
    {
        [$min, $max] = $range;

        if ($level < $min || $level > $max) {
            $subject = $format !== null ? "format \"{$format}\"" : 'zip';

            throw new \InvalidArgumentException(
                "Compression level {$level} is out of range for {$subject} (allowed: {$min}-{$max})."
            );
        }
    }
}
