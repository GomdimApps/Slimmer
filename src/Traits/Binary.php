<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Traits;

use GomdimApps\Slimmer\Exceptions\SlimmerException;

trait Binary
{
    /**
     * Resolve a binary name or absolute path to a verified executable path.
     *
     * @throws SlimmerException If the binary cannot be found or is not executable.
     */
    private function resolveBinary(string $binary): string
    {
        // 1. Caller supplied an absolute path.
        if (str_starts_with($binary, '/')) {
            if (!is_file($binary) || !is_executable($binary)) {
                throw SlimmerException::driverNotInstalled($binary);
            }

            return $binary;
        }

        // 2. Well-known system directories (works even with a stripped $PATH).
        $searchDirs = [
            '/usr/bin',
            '/usr/local/bin',
            '/bin',
            '/usr/sbin',
            '/usr/local/sbin',
            '/snap/bin',
            '/opt/homebrew/bin',
            '/opt/homebrew/sbin',
        ];

        // 3. Merge in any dirs from the process $PATH.
        $envPath = getenv('PATH');
        if ($envPath !== false && $envPath !== '') {
            foreach (explode(':', $envPath) as $dir) {
                $dir = rtrim($dir, '/');
                if ($dir !== '' && !in_array($dir, $searchDirs, true)) {
                    $searchDirs[] = $dir;
                }
            }
        }

        foreach ($searchDirs as $dir) {
            $candidate = $dir . '/' . $binary;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw SlimmerException::driverNotInstalled($binary);
    }
}
