<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Concurrent\SysVSemaphoreSynchronizer;
use RuntimeException;

/**
 * Runs the cross-process scenario of SysVSemaphoreSynchronizer (RN-02 of 1.5.0) and returns the
 * timeline both processes wrote:
 *
 *   the parent enters callExclusively() and signals the child; the child asks for callConcurrently()
 *   on the same named lock; the parent holds the lock a little longer and releases it.
 *
 * With a working lock the child's "child-ran" comes after the parent's "parent-release". The child
 * is a fresh PHP process (tests/Support/sysv_semaphore_child.php) started with pcntl_fork() +
 * pcntl_exec() when pcntl is available, with proc_open() otherwise; neither process may stay stuck
 * (the parent waits for the child with a deadline and kills it when the deadline passes).
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 */
final class SysVCrossProcessProbe
{
    /**
     * @param string $name Lock name used by the parent
     * @param int $maxPermits Concurrent permits
     * @param bool $useProcOpen Start the child with proc_open() even when pcntl is available
     * @param string|null $childName Lock name used by the child (default: the same; a different name is
     *                               the negative control: the child must NOT wait)
     * @return array{mode: string, events: list<array{event: string, at: int}>, childExit: int|null, timedOut: bool, parentReacquired: bool}
     */
    public static function run(string $name, int $maxPermits = 4, bool $useProcOpen = false, ?string $childName = null): array
    {
        $dir = sys_get_temp_dir() . '/aspec_sysv_' . getmypid() . '_' . bin2hex(random_bytes(4));
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create {$dir}");
        }
        $marker = $dir . '/parent-holds';
        $log = $dir . '/timeline.log';
        touch($log);

        $script = __DIR__ . '/sysv_semaphore_child.php';
        $childLock = $childName ?? $name;
        $args = ['-d', 'xdebug.mode=off', $script, $childLock, (string) $maxPermits, $marker, $log];

        $sync = new SysVSemaphoreSynchronizer($name, $maxPermits);
        $write = static function (string $event) use ($log): void {
            file_put_contents($log, $event . ' ' . hrtime(true) . "\n", FILE_APPEND | LOCK_EX);
        };

        [$mode, $handle] = self::spawn($args, $useProcOpen);
        $timedOut = false;
        $childExit = null;
        $parentReacquired = false;

        try {
            $sync->callExclusively(static function () use ($write, $marker, $log): void {
                $write('parent-acquired');
                touch($marker);

                // Wait until the child is about to ask for the lock, then give it time to block on it.
                $deadline = hrtime(true) + 10_000_000_000;
                while (!str_contains((string) file_get_contents($log), 'child-trying') && hrtime(true) < $deadline) {
                    usleep(5_000);
                }
                usleep(300_000);
                $write('parent-release');
            });

            [$childExit, $timedOut] = self::wait($mode, $handle, 15);

            // Nobody stays stuck: the parent takes the exclusive lock again right away.
            $parentReacquired = $sync->callExclusively(static fn(): bool => true);
        } finally {
            if ($timedOut) {
                self::kill($mode, $handle);
            }
            $sync->remove();
            if ($childName !== null && $childName !== $name && SysVSemaphoreSynchronizer::isSupported()) {
                (new SysVSemaphoreSynchronizer($childName, $maxPermits))->remove();
            }
        }

        $events = [];
        foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$event, $at] = explode(' ', $line, 2) + [1 => '0'];
            $events[] = ['event' => $event, 'at' => (int) $at];
        }
        @unlink($marker);
        @unlink($log);
        @rmdir($dir);

        return [
            'mode' => $mode,
            'events' => $events,
            'childExit' => $childExit,
            'timedOut' => $timedOut,
            'parentReacquired' => $parentReacquired,
        ];
    }

    /**
     * @param list<string> $args Script and its arguments
     * @return array{0: string, 1: mixed} Mode and process handle (pid or proc resource)
     */
    private static function spawn(array $args, bool $useProcOpen): array
    {
        if (!$useProcOpen && function_exists('pcntl_fork') && function_exists('pcntl_exec')) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork() failed');
            }
            if ($pid === 0) {
                // Child: replace the image at once, so no state of this (test runner) process runs here.
                // Through /bin/sh only to send the child's stdout/stderr to /dev/null, as proc_open() does.
                if (is_executable('/bin/sh')) {
                    @pcntl_exec('/bin/sh', array_merge(['-c', 'exec "$0" "$@" >/dev/null 2>&1', PHP_BINARY], $args));
                }
                @pcntl_exec(PHP_BINARY, $args);
                if (function_exists('posix_kill')) {
                    posix_kill(getmypid(), 9);
                }
                exit(127);
            }
            return ['pcntl_fork', $pid];
        }

        $process = proc_open(
            array_merge([PHP_BINARY], $args),
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('proc_open() failed');
        }
        fclose($pipes[0]);
        return ['proc_open', $process];
    }

    /**
     * @return array{0: int|null, 1: bool} Exit code and whether the deadline passed
     */
    private static function wait(string $mode, mixed $handle, int $seconds): array
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        while (hrtime(true) < $deadline) {
            if ($mode === 'pcntl_fork') {
                $status = 0;
                $done = pcntl_waitpid($handle, $status, WNOHANG);
                if ($done === $handle) {
                    return [pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1, false];
                }
            } else {
                $info = proc_get_status($handle);
                if (!$info['running']) {
                    proc_close($handle);
                    return [$info['exitcode'], false];
                }
            }
            usleep(10_000);
        }

        return [null, true];
    }

    private static function kill(string $mode, mixed $handle): void
    {
        if ($mode === 'pcntl_fork') {
            if (function_exists('posix_kill')) {
                posix_kill($handle, 9);
            }
            $status = 0;
            pcntl_waitpid($handle, $status);
            return;
        }
        proc_terminate($handle, 9);
        proc_close($handle);
    }
}
