<?php

declare(strict_types=1);

use GomdimApps\Slimmer\Engines\TarEngine;
use GomdimApps\Slimmer\Exceptions\SlimmerException;
use GomdimApps\Slimmer\Exceptions\TarException;
use GomdimApps\Slimmer\Optimizers\CompressTar;
use GomdimApps\Slimmer\Optimizers\ExtractTar;

describe('ExtractTar', function () {

    beforeEach(function () {
        $fixture = makeArchiveFixture('slimmer_extract', [
            'hello.txt'         => str_repeat('Hello, Slimmer!', 50),
            'subdir/nested.txt' => str_repeat('Nested content.', 50),
        ]);
        $this->outputDir = $fixture->outputDir;
        $this->sourceDir = $fixture->sourceDir;

        // Pre-build a real archive to extract/list in each test via CompressTar.
        $this->archive = $this->outputDir . '/source.tar.gz';
        (new CompressTar())->optimize($this->sourceDir, $this->archive);

        $this->extractor = new ExtractTar();
    });

    afterEach(function () {
        removeArchiveFixture((object) ['outputDir' => $this->outputDir, 'sourceDir' => $this->sourceDir]);
    });

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    it('can be instantiated with the default TarEngine', function () {
        expect(new ExtractTar())->toBeInstanceOf(ExtractTar::class);
    });

    it('accepts an injected TarEngine instance', function () {
        expect(new ExtractTar(new TarEngine()))->toBeInstanceOf(ExtractTar::class);
    });

    // -------------------------------------------------------------------------
    // Fluent API — returns same instance
    // -------------------------------------------------------------------------

    it('fluent setters return the same instance', function () {
        expect($this->extractor->withFormat('gz'))->toBe($this->extractor)
            ->and($this->extractor->withStripComponents(1))->toBe($this->extractor)
            ->and($this->extractor->withExclude('*.log'))->toBe($this->extractor)
            ->and($this->extractor->withProgress(function () {}))->toBe($this->extractor);
    });

    // -------------------------------------------------------------------------
    // withFormat() / withStripComponents() validation
    // -------------------------------------------------------------------------

    it('withFormat throws InvalidArgumentException for an unknown format', function () {
        expect(fn () => $this->extractor->withFormat('rar'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('withStripComponents throws InvalidArgumentException for a negative count', function () {
        expect(fn () => $this->extractor->withStripComponents(-1))
            ->toThrow(\InvalidArgumentException::class);
    });

    // -------------------------------------------------------------------------
    // extract() — auto-detected format
    // -------------------------------------------------------------------------

    it('extracts an archive with auto-detected format and returns extracted file paths', function () {
        $extractDir = $this->outputDir . '/extracted';

        $files = $this->extractor->extract($this->archive, $extractDir);

        expect($files)->not->toBeEmpty();

        $baseName = basename($this->sourceDir);
        expect(file_get_contents("{$extractDir}/{$baseName}/hello.txt"))
            ->toBe(file_get_contents($this->sourceDir . '/hello.txt'));
    });

    it('extracts an archive with an explicitly set format', function () {
        $extractDir = $this->outputDir . '/extracted_explicit';

        $files = $this->extractor->withFormat('gz')->extract($this->archive, $extractDir);

        expect($files)->not->toBeEmpty();
    });

    it('extracts with withStripComponents removing the leading directory', function () {
        $extractDir = $this->outputDir . '/extracted_stripped';

        $this->extractor->withStripComponents(1)->extract($this->archive, $extractDir);

        expect(is_file("{$extractDir}/hello.txt"))->toBeTrue();
    });

    it('extracted file paths returned are absolute and actually exist', function () {
        $extractDir = $this->outputDir . '/extracted_paths';

        $files = $this->extractor->extract($this->archive, $extractDir);

        foreach ($files as $file) {
            expect($file)->toStartWith('/')
                ->and(is_file($file))->toBeTrue();
        }
    });

    // -------------------------------------------------------------------------
    // listContents()
    // -------------------------------------------------------------------------

    it('lists the member paths of the archive', function () {
        $entries  = $this->extractor->listContents($this->archive);
        $baseName = basename($this->sourceDir);

        expect($entries)->toContain("{$baseName}/hello.txt")
            ->and($entries)->toContain("{$baseName}/subdir/nested.txt");
    });

    // -------------------------------------------------------------------------
    // dryRun()
    // -------------------------------------------------------------------------

    it('dryRun returns a non-empty string containing -xf', function () {
        $cmd = $this->extractor->dryRun($this->archive, $this->outputDir . '/dry');

        expect($cmd)->toBeString()->toContain('-xf');
    });

    it('dryRun does not create the output directory or extract any files', function () {
        $extractDir = $this->outputDir . '/never_created';

        $this->extractor->dryRun($this->archive, $extractDir);

        expect(is_dir($extractDir))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Progress callback
    // -------------------------------------------------------------------------

    it('withProgress invokes the callback during extraction', function () {
        $seen = [];

        $this->extractor
            ->withProgress(function (string $line) use (&$seen) { $seen[] = $line; })
            ->extract($this->archive, $this->outputDir . '/progress_extract');

        expect($seen)->not->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    it('throws SlimmerException when the archive does not exist', function () {
        expect(fn () => $this->extractor->extract($this->outputDir . '/missing.tar.gz', $this->outputDir . '/x'))
            ->toThrow(SlimmerException::class);
    });

    it('throws TarException::formatDetectionFailed for an undetectable format on an unreadable path', function () {
        $unknown = $this->outputDir . '/mystery_archive';
        file_put_contents($unknown, 'not a real archive, no magic bytes, no known extension');

        expect(fn () => $this->extractor->listContents($unknown))
            ->toThrow(TarException::class);
    });

});
