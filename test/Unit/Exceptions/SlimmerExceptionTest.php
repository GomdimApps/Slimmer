<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Exceptions\SlimmerException;

describe('SlimmerException', function () {

    it('is a RuntimeException', function () {
        $exception = new SlimmerException('test');

        expect($exception)->toBeInstanceOf(RuntimeException::class);
    });

    it('creates an inputFileNotFound exception with the correct message', function () {
        $exception = SlimmerException::inputFileNotFound('/some/missing.pdf');

        expect($exception)
            ->toBeInstanceOf(SlimmerException::class)
            ->and($exception->getMessage())
            ->toContain('/some/missing.pdf');
    });

    it('creates an outputDirectoryNotWritable exception with the correct message', function () {
        $exception = SlimmerException::outputDirectoryNotWritable('/readonly/dir');

        expect($exception)
            ->toBeInstanceOf(SlimmerException::class)
            ->and($exception->getMessage())
            ->toContain('/readonly/dir');
    });

    it('creates an engineNotFound exception with the correct message', function () {
        $exception = SlimmerException::engineNotFound('gs');

        expect($exception)
            ->toBeInstanceOf(SlimmerException::class)
            ->and($exception->getMessage())
            ->toContain('gs');
    });

    it('creates an engineCommandFailed exception carrying the exit code', function () {
        $exception = SlimmerException::engineCommandFailed('gs -dBATCH ...', 1, 'Error: undefined in test');

        expect($exception->getCode())->toBe(1)
            ->and($exception->getMessage())
            ->toContain('exit 1')
            ->toContain('Error: undefined in test');
    });

    it('creates an engineCommandFailed exception without stderr when none is provided', function () {
        $exception = SlimmerException::engineCommandFailed('gs', 2);

        expect($exception->getMessage())->not->toContain('Engine output:');
    });

});
