<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Contracts;

/**
 * Contract for all file optimizers in the Slimmer library.
 *
 * Every optimizer must accept an input file path and an output file path,
 * and return the size reduction ratio achieved (0.0 – 1.0).
 * Implementations are free to add format-specific configuration methods
 * before calling optimize().
 */
interface Optimizer
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
    public function dryRun(?string $inputPath, string $outputPath): string;

    /**
     * Optimize the given input file and write the result to the output path.
     *
     * @param  string|null $inputPath   Absolute path to the source file, or null if using fromStream/fromString.
     * @param  string      $outputPath  Absolute path where the optimized file will be saved.
     *
     * @return float Compression ratio achieved (bytes saved / original size).
     *               Returns 0.0 when no reduction was possible.
     *
     * @throws \GomdimApps\Slimmer\Exceptions\SlimmerException
     */
    public function optimize(?string $inputPath, string $outputPath): float;
}
