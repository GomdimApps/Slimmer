<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Exceptions;

/**
 * Exception for tar compression and retention errors.
 */
class TarException extends SlimmerException
{
    /**
     * Tar compression process failed.
     */
    public static function compressionFailed(string $command, int $code, string $stderr = ''): static
    {
        $message = "Tar compression failed (exit {$code}): \"{$command}\"";

        if ($stderr !== '') {
            $message .= "\nProcess output: {$stderr}";
        }

        return new static($message, $code);
    }

    /**
     * Unsupported archive format requested.
     */
    public static function unsupportedFormat(string $format): static
    {
        return new static(
            "Unsupported archive format \"{$format}\". Supported formats: gz, zst."
        );
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
