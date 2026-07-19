<?php
/**
 * AI Pilot — Standalone Regression Test Runner
 *
 * Usage:
 *   php tests/test-runner.php                  # run all tests
 *   php tests/test-runner.php --filter=test_45 # run a single test by name
 *   php tests/test-runner.php --list           # list available tests
 *   php tests/test-runner.php --verbose        # show per-test details
 *
 * Requirements: PHP 7.4+. No Composer, no WordPress, no network.
 *
 * Exit codes:
 *   0 — all tests passed
 *   1 — at least one test failed
 *   2 — environment error (could not load plugin, etc.)
 */

// ─── Bootstrap: load mock FIRST, then helpers, then the test class ──

$tests_dir = __DIR__;
require_once $tests_dir . '/wp-mock.php';
require_once $tests_dir . '/TestHelpers.php';
require_once $tests_dir . '/RegressionTest.php';

// ─── CLI parsing ─────────────────────────────────────────────────────

$options = getopt('', ['filter:', 'list', 'verbose', 'help', 'stop-on-fail'], $rest_index);
$argv    = array_slice($argv, $rest_index);

$filter       = isset($options['filter']) ? $options['filter'] : null;
$list_only    = isset($options['list']);
$verbose      = isset($options['verbose']);
$stop_on_fail = isset($options['stop-on-fail']);

if (isset($options['help'])) {
    echo "AI Pilot Regression Test Runner\n";
    echo "Usage: php tests/test-runner.php [options]\n\n";
    echo "Options:\n";
    echo "  --filter=NAME       Run only the test whose name contains NAME\n";
    echo "  --list              List all available tests and exit\n";
    echo "  --verbose           Show each test name as it runs\n";
    echo "  --stop-on-fail      Stop after the first failure\n";
    echo "  --help              Show this message\n";
    exit(0);
}

// ─── Load the plugin ─────────────────────────────────────────────────

try {
    TestHelpers::loadPlugin();
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: Could not load plugin: " . $e->getMessage() . "\n");
    fwrite(STDERR, "  at " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(2);
}

// ─── Discover test methods ───────────────────────────────────────────

$test_class = new ReflectionClass('RegressionTest');
$methods = [];
foreach ($test_class->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    if (strpos($m->getName(), 'test_') === 0) {
        $methods[] = $m->getName();
    }
}
sort($methods);

if ($filter !== null) {
    $methods = array_values(array_filter($methods, function($n) use ($filter) {
        return stripos($n, $filter) !== false;
    }));
}

if ($list_only) {
    echo "Available tests (" . count($methods) . "):\n";
    foreach ($methods as $n) echo "  - {$n}\n";
    exit(0);
}

if (empty($methods)) {
    fwrite(STDERR, "ERROR: No tests matched filter '{$filter}'\n");
    exit(2);
}

// ─── Run ─────────────────────────────────────────────────────────────

$instance = new RegressionTest();
$passed = 0;
$failed = 0;
$errors = 0;
$failures_list = [];

if (!$verbose && count($methods) > 5) {
    // Compact progress: print one dot per test
    echo "Running " . count($methods) . " tests:\n";
}

$start = microtime(true);

foreach ($methods as $name) {
    TestHelpers::$current_test = $name;
    TestHelpers::$last_failure_message = '';

    if ($verbose) {
        echo "▶ {$name} ... ";
    }

    try {
        $instance->$name();

        if (TestHelpers::$last_failure_message !== '') {
            // An assertion failed via fail() but didn't throw — treat as failure
            $failed++;
            $failures_list[] = ['test' => $name, 'message' => TestHelpers::$last_failure_message];
            echo $verbose ? "FAIL\n" : 'F';
            if ($stop_on_fail) break;
        } else {
            $passed++;
            echo $verbose ? "PASS\n" : '.';
        }
    } catch (AssertionError $e) {
        $failed++;
        $failures_list[] = ['test' => $name, 'message' => $e->getMessage()];
        echo $verbose ? "FAIL\n    ↳ " . $e->getMessage() . "\n" : 'F';
        if ($stop_on_fail) break;
    } catch (Throwable $e) {
        $errors++;
        $failures_list[] = [
            'test'    => $name,
            'message' => get_class($e) . ': ' . $e->getMessage(),
            'trace'   => $e->getTraceAsString(),
        ];
        echo $verbose ? "ERROR\n    ↳ " . get_class($e) . ": " . $e->getMessage() . "\n" : 'E';
        if ($stop_on_fail) break;
    }
}

$elapsed = microtime(true) - $start;

// ─── Report ──────────────────────────────────────────────────────────

echo "\n\n";
echo "──────────────────────────────────────────────────────────────\n";
echo sprintf(
    "Tests: %d passed, %d failed, %d errored  (total: %d)  in %.2fs\n",
    $passed,
    $failed,
    $errors,
    count($methods),
    $elapsed
);

if (!empty($failures_list)) {
    echo "\nFailures:\n";
    foreach ($failures_list as $i => $f) {
        echo sprintf(
            "\n%d) %s\n   %s\n",
            $i + 1,
            $f['test'],
            $f['message']
        );
        if (isset($f['trace']) && $verbose) {
            echo "   Trace:\n";
            foreach (explode("\n", $f['trace']) as $line) {
                if (trim($line) === '') continue;
                echo "   " . $line . "\n";
            }
        }
    }
}

echo "──────────────────────────────────────────────────────────────\n";

exit(($failed + $errors) > 0 ? 1 : 0);
