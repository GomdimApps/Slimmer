<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Optimizers\Utils\Image;

/**
 * Translates ImageOptimizer's configured state into engine call arguments.
 */
class ImageArgsBuilder
{
    /**
     * Determine the Ghostscript output device from the output file's extension.
     */
    public static function determineDevice(string $outputPath): string
    {
        $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'png16m',
            default => 'jpeg',
        };
    }

    /**
     * Resolve the "{width}x{height}" dimensions string to pass to the engine.
     * Returns the explicit dimensions when set, otherwise probes the source
     * image itself; returns null when neither is available.
     */
    public static function resolveDimensions(?string $explicitDimensions, string $inputPath): ?string
    {
        if ($explicitDimensions !== null) {
            return $explicitDimensions;
        }

        $imageSize = @getimagesize($inputPath);

        if ($imageSize === false) {
            return null;
        }

        return $imageSize[0] . 'x' . $imageSize[1];
    }
}
