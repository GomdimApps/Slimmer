<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers\Utils\Tar;

/**
 * Translates CompressTar's configured flags into per-call extra CLI arguments.
 */
class TarArgsBuilder
{
    /**
     * Build the per-call extra arguments derived from CompressTar's active flags.
     *
     * @return string[]
     */
    public static function build(bool $preservePermissions): array
    {
        $args = [];

        if ($preservePermissions) {
            $args[] = '-p';
        }

        return $args;
    }
}
