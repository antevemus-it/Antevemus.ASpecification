<?php

declare(strict_types=1);

/**
 * A worker process of Module 50 (2.16.0, forward 065): two of them, started by the module as separate PHP processes, race
 * for the locks of the three dispatchers (one PdoLeaseLock, three names) on a real database for a while. Each time one
 * takes a lock it marks the critical section row of that name as its own (an UPDATE that must find the row free) and
 * frees it before releasing the lock; an UPDATE that does not find the row as expected is an overlap, the failure of the
 * lock. The counts go to a JSON file.
 *
 *   php lease_lock_worker.php <dsn> <user> <password> <owner> <start unix time> <duration ms> <result file> <name>...
 *
 * @version 2.16.0
 */

use Antevemus\ASpecification\Persistence\PdoLeaseLock;

require dirname(__DIR__) . '/bootstrap.php';

[, $dsn, $user, $password, $owner, $start, $duration, $resultFile] = $argv;
$names = array_slice($argv, 8);
$result = ['entries' => 0, 'denials' => 0, 'overlaps' => 0, 'error' => null];
try {
    $pdo = new PDO($dsn, $user === '' ? null : $user, $password === '' ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
    if (str_starts_with($dsn, 'sqlite:')) {
        $pdo->exec('PRAGMA busy_timeout = 10000');
    }
    $lock = new PdoLeaseLock($pdo);
    $enter = $pdo->prepare('UPDATE m50_critical SET holder = ? WHERE id = ? AND holder IS NULL');
    $leave = $pdo->prepare('UPDATE m50_critical SET holder = NULL WHERE id = ? AND holder = ?');
    while (microtime(true) < (float) $start) {
        usleep(1000);
    }
    $deadline = (float) $start + ((int) $duration) / 1000;
    for ($i = 0; microtime(true) < $deadline; $i++) {
        $index = $i % count($names);
        $name = $names[$index];
        if (!$lock->tryAcquire($name, $owner, new DateTimeImmutable('now', new DateTimeZone('UTC')), 30)) {
            $result['denials']++;
            usleep(200);
            continue;
        }
        $result['entries']++;
        $enter->execute([$owner, $index + 1]);
        if ($enter->rowCount() !== 1) {
            $result['overlaps']++;
        }
        usleep(random_int(100, 1500));
        $leave->execute([$index + 1, $owner]);
        if ($leave->rowCount() !== 1) {
            $result['overlaps']++;
        }
        $lock->release($name, $owner);
        usleep(random_int(0, 300));
    }
} catch (Throwable $e) {
    $result['error'] = get_class($e) . ': ' . $e->getMessage();
}
file_put_contents($resultFile, json_encode($result));
