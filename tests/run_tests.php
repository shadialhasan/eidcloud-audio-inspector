<?php

declare(strict_types=1);

// Ensure assertions are enabled
ini_set('assert.active', '1');
ini_set('assert.exception', '1');
ini_set('zend.assertions', '1');

// Register PSR-4 autoloader for EidCloud\AudioInspector\ and EidCloud\AudioInspector\Tests\
spl_autoload_register(function (string $class): void {
    $prefix = 'EidCloud\\AudioInspector\\';
    if (str_starts_with($class, $prefix)) {
        $rel = substr($class, strlen($prefix));
        if (str_starts_with($rel, 'Tests\\')) {
            $testRel = substr($rel, 6);
            $file = __DIR__ . '/' . str_replace('\\', '/', $testRel) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use EidCloud\AudioInspector\Tests\AudioInspectorTest;

echo "\n";
echo "\033[1;36m========================================================================\033[0m\n";
echo "\033[1;36m  🎙️  EIDCLOUD AUDIO INSPECTOR - AUTOMATED TEST SUITE\033[0m\n";
echo "\033[2m  Zero-Dependency Verification: WAV Headers, Acoustic Forensics & Standards\033[0m\n";
echo "\033[1;36m========================================================================\033[0m\n\n";

$testCase = new AudioInspectorTest();
$reflection = new ReflectionClass($testCase);
$methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

$testMethods = array_filter($methods, function (ReflectionMethod $m): bool {
    return str_starts_with($m->getName(), 'test');
});

$passed = 0;
$failed = 0;
$startTime = microtime(true);

foreach ($testMethods as $method) {
    $name = $method->getName();
    $methodStart = microtime(true);
    echo sprintf("  %-50s ", $name . '()');

    try {
        $method->invoke($testCase);
        $elapsedMs = round((microtime(true) - $methodStart) * 1000, 1);
        echo "\033[1;32m[ PASS ]\033[0m \033[2m({$elapsedMs}ms)\033[0m\n";
        $passed++;
    } catch (\Throwable $e) {
        $elapsedMs = round((microtime(true) - $methodStart) * 1000, 1);
        echo "\033[1;31m[ FAIL ]\033[0m \033[2m({$elapsedMs}ms)\033[0m\n";
        echo "    \033[31mError: " . $e->getMessage() . "\033[0m\n";
        echo "    \033[2m" . $e->getFile() . ':' . $e->getLine() . "\033[0m\n\n";
        $failed++;
    }
}

$testCase->cleanTempFiles();

$totalTime = round((microtime(true) - $startTime) * 1000, 1);
echo "\n" . str_repeat('─', 72) . "\n";
if ($failed === 0) {
    echo sprintf(
        "\033[1;32m  ✔ ALL TESTS PASSED (%d/%d tests) in %0.1f ms\033[0m\n",
        $passed,
        $passed + $failed,
        $totalTime
    );
    echo str_repeat('─', 72) . "\n\n";
    exit(0);
} else {
    echo sprintf(
        "\033[1;31m  ✘ %d TEST(S) FAILED out of %d in %0.1f ms\033[0m\n",
        $failed,
        $passed + $failed,
        $totalTime
    );
    echo str_repeat('─', 72) . "\n\n";
    exit(1);
}
