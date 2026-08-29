<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Exceptions;

/**
 * Exception for zip compression, extraction and retention errors.
 */
class ZipException extends SlimmerException
{
    /**
     * Zip compression process failed.
     */
    public static function compressionFailed(string $command, int $code, string $stderr = ''): static
    {
        $message = "Zip compression failed (exit {$code}): \"{$command}\"";

        if ($stderr !== '') {
            $message .= "\nProcess output: {$stderr}";
        }

        return new static($message, $code);
    }

    /**
     * Zip extraction process failed.
     */
    public static function extractionFailed(string $command, int $code, string $stderr = ''): static
    {
        $message = "Zip extraction failed (exit {$code}): \"{$command}\"";

        if ($stderr !== '') {
            $message .= "\nProcess output: {$stderr}";
        }

        return new static($message, $code);
    }

    /**
     * Listing an archive's contents failed.
     */
    public static function listingFailed(string $command, int $code, string $stderr = ''): static
    {
        $message = "Zip listing failed (exit {$code}): \"{$command}\"";

        if ($stderr !== '') {
            $message .= "\nProcess output: {$stderr}";
        }

        return new static($message, $code);
    }

    /**
     * Deletion of a source file or directory failed during a retention strategy.
     */
    public static function deletionFailed(string $path, string $reason = ''): static
    {
        $message = "Failed to delete \"{$path}\" during source cleanup.";

        if ($reason !== '') {
            $message .= " Reason: {$reason}";
        }

        return new static($message);
    }

    /**
     * Removal of old archives during directory retention cleanup failed.
     */
    public static function retentionCleanupFailed(string $directory, string $reason = ''): static
    {
        $message = "Retention cleanup failed in directory \"{$directory}\".";

        if ($reason !== '') {
            $message .= " Reason: {$reason}";
        }

        return new static($message);
    }
}
