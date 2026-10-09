<?php

declare(strict_types=1);

/**
 * Child process of the cross-process SysVSemaphoreSynchronizer test (RN-02 of 1.5.0).
 *
 * Usage: php sysv_semaphore_child.php <name> <maxPermits> <marker file> <log file>
 *
 * Waits until the parent signals (marker file) that it holds the exclusive lock, logs
 * "child-trying", then asks for a concurrent block on the same named lock and logs "child-ran"
 * from inside it. With a working lock "child-ran" can only come after the parent's
 * "parent-release". Started by SysVCrossProcessProbe through pcntl_fork() + pcntl_exec() when
 * pcntl is available, through proc_open() otherwise.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Antevemus\ASpecification\Concurrent\SysVSemaphoreSynchronizer;

[, $name, $maxPermits, $marker, $log] = $argv + [null, '', '4', '', ''];

$write = static function (string $event) use ($log): void {
    file_put_contents($log, $event . ' ' . hrtime(true) . "\n", FILE_APPEND | LOCK_EX);
};

try {
    $deadline = hrtime(true) + 10_000_000_000;
    while (!is_file($marker)) {
        if (hrtime(true) > $deadline) {
            $write('child-gave-up-waiting-for-parent');
            exit(3);
        }
        usleep(5_000);
    }

    $sync = new SysVSemaphoreSynchronizer($name, (int) $maxPermits);
    $write('child-trying');
    $sync->callConcurrently(static function () use ($write): void {
        $write('child-ran');
    });
    $write('child-done');
    exit(0);
} catch (Throwable $e) {
    $write('child-error:' . str_replace(["\n", ' '], '_', get_class($e) . ':' . $e->getMessage()));
    exit(4);
}
