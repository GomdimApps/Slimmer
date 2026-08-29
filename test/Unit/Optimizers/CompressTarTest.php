<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Engines\TarEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\TarException;
use GomdimApps\Slimmer\Optimizers\CompressTar;

describe('CompressTar', function () {

    beforeEach(function () {
        $this->documentDir = dirname(__DIR__, 3) . '/document';

        $fixture = makeArchiveFixture('slimmer_tar_opt', [
            'hello.txt'         => str_repeat('Hello, Slimmer!', 100),
            'data.json'         => str_repeat('{"key":"value"}', 150),
            'subdir/nested.txt' => str_repeat('Nested content.', 100),
        ], withEmptyDir: true);
        $this->outputDir = $fixture->outputDir;
        $this->sourceDir = $fixture->sourceDir;

        $this->optimizer = new CompressTar();
    });

    afterEach(function () {
        removeArchiveFixture((object) ['outputDir' => $this->outputDir, 'sourceDir' => $this->sourceDir]);
    });

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    it('can be instantiated with the default TarEngine', function () {
        expect(new CompressTar())->toBeInstanceOf(CompressTar::class);
    });

    it('accepts an injected TarEngine instance', function () {
        $engine = new TarEngine();

        expect(new CompressTar($engine))->toBeInstanceOf(CompressTar::class);
    });

    // -------------------------------------------------------------------------
    // Fluent API — returns same instance
    // -------------------------------------------------------------------------

    it('fluent setters return the same instance', function () {
        expect($this->optimizer->withFormat('gz'))->toBe($this->optimizer)
            ->and($this->optimizer->withCompressionLevel(9))->toBe($this->optimizer)
            ->and($this->optimizer->withThreads(4))->toBe($this->optimizer)
            ->and($this->optimizer->withExclude('*.log'))->toBe($this->optimizer)
            ->and($this->optimizer->withCustomArgs('--verbose'))->toBe($this->optimizer)
            ->and($this->optimizer->preservePermissions())->toBe($this->optimizer)
            ->and($this->optimizer->ignoreEmptyDirectories())->toBe($this->optimizer)
            ->and($this->optimizer->withProgress(function () {}))->toBe($this->optimizer);
    });

    // -------------------------------------------------------------------------
    // withFormat() validation
    // -------------------------------------------------------------------------

    it('withFormat accepts a supported format', function (string $format) {
        expect($this->optimizer->withFormat($format))->toBe($this->optimizer);
    })->with(['gz', 'zst', 'bz2']);

    it('withFormat throws InvalidArgumentException for an unknown format', function () {
        expect(fn () => $this->optimizer->withFormat('rar'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('withFormat exception message mentions the unsupported format', function () {
        expect(fn () => $this->optimizer->withFormat('xz'))
            ->toThrow(\InvalidArgumentException::class, 'xz');
    });

    // -------------------------------------------------------------------------
    // withExclude() — accumulates patterns
    // -------------------------------------------------------------------------

    it('withExclude accumulates patterns across multiple calls', function () {
        $this->optimizer->withExclude('*.log')->withExclude('*.tmp');

        $cmd = $this->optimizer->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toContain('--exclude=*.log')
            ->and($cmd)->toContain('--exclude=*.tmp');
    });

    // -------------------------------------------------------------------------
    // optimize() — .tar.gz with the document folder
    // -------------------------------------------------------------------------

    it('optimize compresses the document folder and returns a float ratio', function () {
        $output = $this->outputDir . '/document.tar.gz';
        $ratio  = $this->optimizer->optimize($this->documentDir, $output);

        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and($ratio)->toBeLessThanOrEqual(1.0);
    });

    it('optimize creates a non-empty .tar.gz archive of the document folder', function () {
        $output = $this->outputDir . '/document.tar.gz';

        $this->optimizer->optimize($this->documentDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and(filesize($output))->toBeGreaterThan(0);
    });

    // -------------------------------------------------------------------------
    // optimize() — source directory with known content
    // -------------------------------------------------------------------------

    it('optimize creates a .tar.<ext> archive from the source directory', function (string $format) {
        $output = $this->outputDir . "/archive.tar.{$format}";

        $ratio = $this->optimizer->withFormat($format)->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and(filesize($output))->toBeGreaterThan(0)
            ->and($ratio)->toBeFloat();
    })->with(['gz', 'zst', 'bz2']);

    it('optimize returns a ratio between 0.0 and 1.0', function () {
        $output = $this->outputDir . '/ratio_test.tar.gz';
        $ratio  = $this->optimizer->optimize($this->sourceDir, $output);

        expect($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and($ratio)->toBeLessThanOrEqual(1.0);
    });

    it('optimize auto-names the archive when outputPath has no extension', function () {
        $this->optimizer->optimize($this->sourceDir, $this->outputDir);

        $files = glob($this->outputDir . '/*.tar.gz') ?: [];

        expect($files)->not->toBeEmpty();
    });

    it('optimize with compression level 9 produces a valid archive', function () {
        $output = $this->outputDir . '/max.tar.gz';

        $ratio = $this->optimizer->withCompressionLevel(9)->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('optimize with withExclude omits the specified pattern', function () {
        $output = $this->outputDir . '/no_json.tar.gz';

        $this->optimizer->withExclude('*.json')->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('optimize with ignoreEmptyDirectories produces a valid archive', function () {
        $output = $this->outputDir . '/no_empty.tar.gz';

        $this->optimizer->ignoreEmptyDirectories()->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('optimize with preservePermissions produces a valid archive', function () {
        $output = $this->outputDir . '/perms.tar.gz';

        $this->optimizer->preservePermissions()->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('optimize with custom args produces a valid archive', function () {
        $output = $this->outputDir . '/custom.tar.gz';

        $this->optimizer->withCustomArgs('--verbose')->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // optimize() — .tar.zst
    // -------------------------------------------------------------------------
    // (the basic "creates a .tar.zst archive" check was merged into the gz/zst/bz2
    // dataset test above.)

    it('optimize with zst and multiple threads produces a valid archive', function () {
        $output = $this->outputDir . '/parallel.tar.zst';

        $this->optimizer->withFormat('zst')->withThreads(2)->optimize($this->sourceDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    it('optimize compresses the document folder into a .tar.zst archive', function () {
        $output = $this->outputDir . '/document.tar.zst';

        $this->optimizer->withFormat('zst')->optimize($this->documentDir, $output);

        expect(is_file($output))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // optimize() — fromString / fromStream
    // -------------------------------------------------------------------------

    it('optimize compresses content supplied via fromString', function () {
        $output  = $this->outputDir . '/from_string.tar.gz';
        $content = str_repeat('sample content for compression test', 200);

        $ratio = $this->optimizer->fromString($content)->optimize(null, $output);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    it('optimize compresses content supplied via fromStream', function () {
        $output = $this->outputDir . '/from_stream.tar.gz';
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, str_repeat('stream content for compression test', 200));
        rewind($stream);

        $ratio = $this->optimizer->fromStream($stream)->optimize(null, $output);
        fclose($stream);

        expect(is_file($output))->toBeTrue()
            ->and($ratio)->toBeFloat();
    });

    // -------------------------------------------------------------------------
    // optimize() — exception paths
    // -------------------------------------------------------------------------

    it('throws SlimmerException when the input path does not exist', function () {
        expect(fn () => $this->optimizer->optimize('/nonexistent/path/to/dir', $this->outputDir))
            ->toThrow(SlimmerException::class);
    });

    it('throws SlimmerException when the output directory does not exist', function () {
        expect(fn () => $this->optimizer->optimize($this->sourceDir, '/nonexistent/dir/archive.tar.gz'))
            ->toThrow(SlimmerException::class);
    });

    it('throws SlimmerException when no input is provided and fromString/fromStream were not called', function () {
        expect(fn () => $this->optimizer->optimize(null, $this->outputDir . '/out.tar.gz'))
            ->toThrow(SlimmerException::class);
    });

    // -------------------------------------------------------------------------
    // dryRun()
    // -------------------------------------------------------------------------

    it('dryRun returns a non-empty string', function () {
        $cmd = $this->optimizer->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toBeString()->not->toBeEmpty();
    });

    it('dryRun contains the right compress program per format', function (string $format, string $program) {
        $cmd = $this->optimizer->withFormat($format)
            ->dryRun($this->sourceDir, $this->outputDir . "/out.tar.{$format}");

        expect($cmd)->toContain($program);
    })->with([
        ['gz', 'gzip'], ['zst', 'zstd'], ['bz2', 'bzip2'],
    ]);

    it('dryRun includes -p when preservePermissions is enabled', function () {
        $cmd = $this->optimizer->preservePermissions()
            ->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toContain(' -p');
    });

    it('dryRun includes --exclude when withExclude is set', function () {
        $cmd = $this->optimizer->withExclude('*.log')
            ->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toContain('--exclude=*.log');
    });

    it('dryRun includes custom args', function () {
        $cmd = $this->optimizer->withCustomArgs('--verbose')
            ->dryRun($this->sourceDir, $this->outputDir . '/out.tar.gz');

        expect($cmd)->toContain('--verbose');
    });

    it('dryRun auto-resolves the output filename in the returned command', function () {
        $cmd = $this->optimizer->dryRun($this->sourceDir, $this->outputDir);

        expect($cmd)->toContain('.tar.gz');
    });

    it('dryRun does not create any files', function () {
        $this->optimizer->dryRun($this->sourceDir, $this->outputDir);

        $files = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect($files)->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // compressAndRetain()
    // -------------------------------------------------------------------------

    it('compressAndRetain compresses the source and returns a valid ratio', function () {
        $retainSrc = sys_get_temp_dir() . '/slimmer_retain_src_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/data.txt', str_repeat('Retain test data.', 200));

        $ratio = $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 5);

        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.0)
            ->and($ratio)->toBeLessThanOrEqual(1.0);
    });

    it('compressAndRetain deletes the source directory after compression', function () {
        $retainSrc = sys_get_temp_dir() . '/slimmer_retain_del_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/file.txt', 'content to compress and delete');

        $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 5);

        expect(is_dir($retainSrc))->toBeFalse();
    });

    it('compressAndRetain deletes a source file after compression', function () {
        $retainFile = sys_get_temp_dir() . '/slimmer_retain_file_' . uniqid() . '.txt';
        file_put_contents($retainFile, str_repeat('file content', 100));

        $this->optimizer->compressAndRetain($retainFile, $this->outputDir, 5);

        expect(is_file($retainFile))->toBeFalse();
    });

    it('compressAndRetain keeps only the $limit most-recent archives', function () {
        // Pre-populate output dir with 4 old fake archives
        for ($i = 1; $i <= 4; $i++) {
            $file = $this->outputDir . "/old_{$i}.tar.gz";
            file_put_contents($file, "fake archive {$i}");
            touch($file, time() - (500 - $i * 10));
        }

        $retainSrc = sys_get_temp_dir() . '/slimmer_retain_limit_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/data.txt', str_repeat('x', 1000));

        $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 2);

        $remaining = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect(count($remaining))->toBe(2);
    });

    it('compressAndRetain creates the new archive in the output directory', function () {
        $retainSrc = sys_get_temp_dir() . '/slimmer_retain_new_' . uniqid();
        mkdir($retainSrc);
        file_put_contents($retainSrc . '/data.txt', str_repeat('archive content', 100));

        $this->optimizer->compressAndRetain($retainSrc, $this->outputDir, 5);

        $files = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect($files)->not->toBeEmpty();
    });

    it('compressAndRetain throws SlimmerException when the input path does not exist', function () {
        expect(fn () => $this->optimizer->compressAndRetain('/nonexistent/path', $this->outputDir, 5))
            ->toThrow(SlimmerException::class);
    });

    // Regression guard (approved refactor fix): CompressTar must still throw TarException
    // (not some other type) when source deletion fails during compressAndRetain(), now that
    // the deletion-failure exception is supplied via a SourceFiles trait hook shared with Zip.
    it('compressAndRetain throws TarException when source deletion fails', function () {
        $lockedDir  = sys_get_temp_dir() . '/slimmer_tar_locked_' . uniqid();
        mkdir($lockedDir);
        $retainFile = $lockedDir . '/data.txt';
        file_put_contents($retainFile, str_repeat('x', 100));

        chmod($lockedDir, 0500); // read+execute, no write -> unlink() of $retainFile will fail

        // Suppress the genuine unlink() permission warning this deliberately triggers,
        // so the test reports pass/fail on the assertion alone, not as a PHPUnit warning.
        set_error_handler(static fn () => true);

        try {
            expect(fn () => $this->optimizer->compressAndRetain($retainFile, $this->outputDir, 5))
                ->toThrow(TarException::class);
        } finally {
            restore_error_handler();
            chmod($lockedDir, 0755);
            @unlink($retainFile);
            @rmdir($lockedDir);
        }
    });

    // -------------------------------------------------------------------------
    // cleanDirectory()
    // -------------------------------------------------------------------------

    it('cleanDirectory removes archives beyond the $limit', function () {
        for ($i = 1; $i <= 5; $i++) {
            $file = $this->outputDir . "/archive_{$i}.tar.gz";
            file_put_contents($file, "archive {$i}");
            touch($file, time() - (500 - $i * 10));
        }

        $this->optimizer->cleanDirectory($this->outputDir, 3);

        $remaining = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect(count($remaining))->toBe(3);
    });

    it('cleanDirectory retains the most-recent archives ordered by mtime', function () {
        $newest = $this->outputDir . '/newest.tar.gz';
        $oldest = $this->outputDir . '/oldest.tar.gz';

        file_put_contents($oldest, 'old');
        file_put_contents($newest, 'new');
        touch($oldest, time() - 300);
        touch($newest, time());

        $this->optimizer->cleanDirectory($this->outputDir, 1);

        expect(is_file($newest))->toBeTrue()
            ->and(is_file($oldest))->toBeFalse();
    });

    it('cleanDirectory does nothing when archive count is within the limit', function () {
        file_put_contents($this->outputDir . '/a.tar.gz', 'a');
        file_put_contents($this->outputDir . '/b.tar.gz', 'b');

        $this->optimizer->cleanDirectory($this->outputDir, 5);

        $remaining = glob($this->outputDir . '/*.tar.gz') ?: [];

        expect(count($remaining))->toBe(2);
    });

    it('cleanDirectory does nothing when the directory does not exist', function () {
        expect(fn () => $this->optimizer->cleanDirectory('/nonexistent/dir', 3))
            ->not->toThrow(\Throwable::class);
    });

    it('cleanDirectory handles .tar.zst and .tar.gz files together', function () {
        file_put_contents($this->outputDir . '/a.tar.gz',  'gz archive a');
        file_put_contents($this->outputDir . '/b.tar.zst', 'zst archive b');
        file_put_contents($this->outputDir . '/c.tar.gz',  'gz archive c');
        touch($this->outputDir . '/a.tar.gz',  time() - 200);
        touch($this->outputDir . '/b.tar.zst', time() - 100);
        touch($this->outputDir . '/c.tar.gz',  time());

        $this->optimizer->cleanDirectory($this->outputDir, 2);

        $remaining = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect(count($remaining))->toBe(2);
    });

    it('cleanDirectory with limit 0 removes all archives', function () {
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($this->outputDir . "/archive_{$i}.tar.gz", "content {$i}");
        }

        $this->optimizer->cleanDirectory($this->outputDir, 0);

        $remaining = array_merge(
            glob($this->outputDir . '/*.tar.gz')  ?: [],
            glob($this->outputDir . '/*.tar.zst') ?: []
        );

        expect($remaining)->toBeEmpty();
    });

    it('cleanDirectory counts .tar.bz2 files together with .tar.gz and .tar.zst', function () {
        file_put_contents($this->outputDir . '/a.tar.gz',  'gz');
        file_put_contents($this->outputDir . '/b.tar.bz2', 'bz2');
        touch($this->outputDir . '/a.tar.gz',  time() - 100);
        touch($this->outputDir . '/b.tar.bz2', time());

        $this->optimizer->cleanDirectory($this->outputDir, 1);

        expect(is_file($this->outputDir . '/b.tar.bz2'))->toBeTrue()
            ->and(is_file($this->outputDir . '/a.tar.gz'))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // .tar.bz2 format
    // -------------------------------------------------------------------------
    // (the basic "creates a .tar.bz2 archive" and "dryRun contains bzip2" checks
    // were merged into their gz/zst/bz2 dataset tests above.)

    // -------------------------------------------------------------------------
    // Compression-level validation
    // -------------------------------------------------------------------------

    it('withCompressionLevel validates against the current format', function () {
        expect(fn () => $this->optimizer->withCompressionLevel(15))
            ->toThrow(\InvalidArgumentException::class); // default format is gz, max 9
    });

    it('withCompressionLevel accepts a level valid for the current format', function () {
        expect($this->optimizer->withFormat('zst')->withCompressionLevel(15))->toBe($this->optimizer);
    });

    it('withFormat re-validates the already-configured compression level', function () {
        $this->optimizer->withCompressionLevel(9); // valid for gz (default)

        expect(fn () => $this->optimizer->withFormat('zst')->withCompressionLevel(9)->withFormat('gz'))
            ->not->toThrow(\InvalidArgumentException::class);
    });

    it('withFormat throws when switching to a format the current level does not fit', function () {
        $this->optimizer->withFormat('zst')->withCompressionLevel(15); // only valid for zst

        expect(fn () => $this->optimizer->withFormat('gz'))
            ->toThrow(\InvalidArgumentException::class);
    });

    // -------------------------------------------------------------------------
    // withProgress()
    // -------------------------------------------------------------------------
    // (chainability is covered by "fluent setters return the same instance" above.)

    it('withProgress invokes the callback while compressing', function () {
        $seen = [];

        $this->optimizer
            ->withProgress(function (string $line) use (&$seen) { $seen[] = $line; })
            ->optimize($this->sourceDir, $this->outputDir . '/progress.tar.gz');

        expect($seen)->not->toBeEmpty();
    });

});
