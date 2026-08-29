<?php

declare(strict_types=1);

namespace GomdimApps\Slimmer\Traits;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Shared subprocess execution for engines that shell out via Symfony Process
 * (GhostscriptEngine, TarEngine, ZipEngine): builds the process, applies the
 * timeout, optionally pipes stdin input and/or a working directory, and
 * optionally streams complete output lines (blank lines filtered) to a
 * progress callback. Failure and timeout handling are left to the caller via
 * closures, since each engine throws its own exception subtype.
 */
trait ProcessExecution
{
    /**
     * @param string[]                        $argv
     * @param string|null                      $input      Piped to stdin when given.
     * @param string|null                      $cwd        Working directory for the subprocess, if any.
     * @param callable|null                    $onProgress Called with one complete, non-blank line per emitted line.
     * @param callable(int, string): never     $onFailure  Called with (exitCode, stderr) on non-zero exit.
     * @param callable(): never                $onTimeout  Called when the process exceeds its configured timeout.
     */
    private function runProcess(
        array $argv,
        ?string $input,
        ?string $cwd,
        ?callable $onProgress,
        callable $onFailure,
        callable $onTimeout
    ): void {
        $process = new Process($argv, $cwd);
        $process->setTimeout($this->timeout > 0 ? $this->timeout : null);

        if ($input !== null) {
            $process->setInput($input);
        }

        try {
            if ($onProgress !== null) {
                $buffer = '';
                $process->run(function (string $type, string $chunk) use ($onProgress, &$buffer): void {
                    $buffer .= $chunk;
                    $lines = explode("\n", $buffer);
                    $buffer = array_pop($lines);
                    foreach ($lines as $line) {
                        if (trim($line) !== '') {
                            $onProgress($line);
                        }
                    }
                });
                if (trim($buffer) !== '') {
                    $onProgress($buffer);
                }
            } else {
                $process->run();
            }
        } catch (ProcessTimedOutException) {
            $onTimeout();
        }

        if (!$process->isSuccessful()) {
            $onFailure($process->getExitCode() ?? 1, trim($process->getErrorOutput()));
        }
    }
}
