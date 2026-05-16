# Testing Standards

Test all logic using **Pest PHP**.

### Standards

Tests must be organized, maintainable, and written **strictly in English**. Tests must reflect real-world usage and verify both success and failure states.

## Running Tests

The project includes a Docker environment to ensure Ghostscript is available during tests.

```bash
# Using the Makefile (recommended)
make test
```

## Structure

```
test/
├── Pest.php                       # Global configuration
├── Unit/
│   ├── Engines/
│   │   └── GhostscriptEngineTest.php # Engine subprocess tests
│   ├── Exceptions/
│   │   └── SlimmerExceptionTest.php  # Exception factory tests
│   └── Optimizers/
│       └── PdfOptimizerTest.php      # Fluent API & integration tests
```

---

## Example: Engine Test (Subprocess)

**`test/Unit/Engines/GhostscriptEngineTest.php`**

```php
<?php

use GomdimApps\Slimmer\Engines\GhostscriptEngine;

describe('GhostscriptEngine', function () {

    beforeEach(function () {
        $this->samplePdf  = dirname(__DIR__, 3) . '/document/sample.pdf';
        $this->outputPath = sys_get_temp_dir() . '/slimmer_test_' . uniqid() . '.pdf';
        $this->engine     = new GhostscriptEngine();
    });

    afterEach(function () {
        if (is_file($this->outputPath)) @unlink($this->outputPath);
    });

    it('compresses a PDF and produces a valid output', function () {
        $this->engine->compress($this->samplePdf, $this->outputPath);

        expect(is_file($this->outputPath))->toBeTrue()
            ->and(filesize($this->outputPath))->toBeGreaterThan(0);

        // Validate PDF header
        $handle = fopen($this->outputPath, 'rb');
        expect(fread($handle, 5))->toBe('%PDF-');
        fclose($handle);
    });

});
```

## Example: Optimizer Test (Fluent API)

**`test/Unit/Optimizers/PdfOptimizerTest.php`**

```php
<?php

use GomdimApps\Slimmer\Optimizers\PdfOptimizer;

describe('PdfOptimizer', function () {

    beforeEach(function () {
        $this->optimizer = new PdfOptimizer();
    });

    it('returns the same instance for fluent method chaining', function () {
        $instance = $this->optimizer
            ->withQuality('screen')
            ->withCompatibilityLevel('1.4');

        expect($instance)->toBe($this->optimizer);
    });

    it('returns a float ratio after optimization', function () {
        $input  = dirname(__DIR__, 3) . '/document/sample.pdf';
        $output = sys_get_temp_dir() . '/out.pdf';

        $ratio = $this->optimizer->optimize($input, $output);

        expect($ratio)->toBeFloat()
            ->and($ratio)->toBeGreaterThanOrEqual(0.0);

        @unlink($output);
    });

});
```

## Standards Summary

| Aspect           | Standard                                                                |
| :--------------- | :---------------------------------------------------------------------- |
| **Organization** | Organized by namespace under `test/Unit/`.                              |
| **Filesystem**   | Use `sys_get_temp_dir()` for outputs; always clean up in `afterEach()`. |
| **Real Data**    | Use `document/sample.pdf` for real integration tests.                   |
| **Exceptions**   | Test error paths (missing files, bad binaries) using `toThrow()`.       |
| **Naming**       | Strictly English: `it('throws exception if input missing')`.            |
| **Engine**       | Verify exit codes and stderr when an external command fails.            |

## Mandatory Engine Validation

Since this library relies on external binaries, tests **MUST** verify:

1.  **Binary Resolution**: Engine should throw if `gs` is not found.
2.  **Command Failure**: Engine should throw `SlimmerException` if Ghostscript returns a non-zero exit code, capturing the `stderr` message.

### ✅ Correct Pattern

```php
it('throws SlimmerException on command failure', function () {
    // Force a failure with a bad argument
    expect(fn() => $this->engine->compress($this->samplePdf, $this->outputPath, '/invalid'))
        ->toThrow(SlimmerException::class);
});
```

## Tools

- **Pest PHP ^3.0**: Core testing framework.
- **Docker Compose**: Provides the Alpine + Ghostscript environment.
- **sys_get_temp_dir()**: Standard PHP temp directory for test artifacts.
- **expect()**: Pest's expectation API for readable assertions.
