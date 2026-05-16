<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Traits;

use GomdimApps\Slimmer\Exceptions\SlimmerException;

trait InteractsWithTemporaryInput
{
    /** @var string|null */
    protected ?string $temporaryInputFile = null;

    /**
     * Set input from a stream resource.
     *
     * @param resource $stream
     */
    public function fromStream($stream): static
    {
        $this->temporaryInputFile = tempnam(sys_get_temp_dir(), 'slimmer_');
        $handle = fopen($this->temporaryInputFile, 'wb');
        if ($handle === false) {
            throw new SlimmerException("Failed to create temporary file for stream.");
        }
        stream_copy_to_stream($stream, $handle);
        fclose($handle);

        return $this;
    }

    /**
     * Set input from a string content.
     */
    public function fromString(string $content): static
    {
        $this->temporaryInputFile = tempnam(sys_get_temp_dir(), 'slimmer_');
        if (file_put_contents($this->temporaryInputFile, $content) === false) {
            throw new SlimmerException("Failed to write to temporary file.");
        }

        return $this;
    }

    /**
     * Resolve the input path to use, preferring the temporary file if one was created.
     * @throws SlimmerException
     */
    protected function resolveInputPath(?string $inputPath): string
    {
        if ($this->temporaryInputFile !== null) {
            return $this->temporaryInputFile;
        }

        if ($inputPath === null) {
            throw new SlimmerException("No input file provided. Use fromStream(), fromString(), or pass a path.");
        }

        return $inputPath;
    }

    /**
     * Clean up the temporary file if it exists.
     */
    public function __destruct()
    {
        if ($this->temporaryInputFile !== null && is_file($this->temporaryInputFile)) {
            @unlink($this->temporaryInputFile);
        }
    }
}
