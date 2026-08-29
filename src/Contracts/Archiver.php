<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Contracts;

/**
 * Contract for archive-reading optimizers in the Slimmer library.
 *
 * Unlike Optimizer (compress-only, returns a size-reduction ratio), extraction
 * has no ratio to report, so this is a separate, minimal contract shared by
 * every "read an existing archive" class (ExtractTar, ExtractZip, ...).
 */
interface Archiver
{
    /**
     * Set input from a stream resource.
     *
     * @param resource $stream
     */
    public function fromStream($stream): static;

    /**
     * Set input from a string content.
     */
    public function fromString(string $content): static;

    /**
     * Return the exact string of the command that would be executed, without starting the process.
     */
    public function dryRun(?string $inputPath, string $outputDir): string;

    /**
     * Extract the given archive into $outputDir.
     *
     * @param  string|null $inputPath Absolute path to the archive, or null if using fromStream/fromString.
     * @param  string      $outputDir Absolute path to extract into (created if missing).
     *
     * @return string[] Absolute paths of the extracted files.
     *
     * @throws \GomdimApps\Slimmer\Exceptions\SlimmerException
     */
    public function extract(?string $inputPath, string $outputDir): array;
}
