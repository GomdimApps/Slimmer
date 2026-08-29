<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Exceptions;

use RuntimeException;

/**
 * Base exception for all Slimmer errors.
 */
class SlimmerException extends RuntimeException
{
    /** Input file not found or not readable */
    public static function inputFileNotFound(string $path): static
    {
        return new static("Input file not found or not readable: \"{$path}\".");
    }

    /** Output directory not writable */
    public static function outputDirectoryNotWritable(string $directory): static
    {
        return new static("Output directory is not writable: \"{$directory}\".");
    }

    /**
     * Engine binary not found.
     *
     * @deprecated Never thrown in this library — the actual "binary not found" path uses
     *             driverNotInstalled() instead. Kept for backward compatibility only.
     * @see driverNotInstalled()
     */
    public static function engineNotFound(string $binary): static
    {
        return new static(
            "Required engine binary \"{$binary}\" was not found. "
            . "Please install it and make sure it is on your PATH."
        );
    }

    /** Required driver/dependency not installed */
    public static function driverNotInstalled(string $driver): static
    {
        return new static("Required driver not installed: {$driver}.");
    }

    /** Engine command failed */
    public static function engineCommandFailed(string $command, int $code, string $stderr = ''): static
    {
        $message = "Engine command failed (exit {$code}): \"{$command}\"";

        if ($stderr !== '') {
            $message .= "\nEngine output: {$stderr}";
        }

        return new static($message, $code);
    }

    /**
     * Optimized file is larger than original.
     *
     * @deprecated Unused in this library; also carries a typo ("Dize" should read "Size").
     *             Kept for backward compatibility only — do not call in new code.
     */
    public static function optimizationIncreasesDize(string $outputPath, int $originalBytes, int $outputBytes): static
    {
        return new static(
            "Optimization produced a larger file ({$outputBytes} bytes) "
            . "than the original ({$originalBytes} bytes): \"{$outputPath}\"."
        );
    }
}