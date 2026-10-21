<?php

declare(strict_types=1);

/**
 * Child process of the persistOnShutdown() tests (Module 42).
 *
 * Usage: php write_behind_child.php <storage-path> <mode> <put-count>
 *
 * Opens a write-behind hybrid (MemoryAsyncFile) over <storage-path>, puts <put-count> new entities and
 * ends without close() nor persist(). <mode>: "shutdown" registers persistOnShutdown() and returns;
 * "none" registers nothing; "kill" registers persistOnShutdown() and then kills its own process with
 * SIGKILL, so no shutdown function runs; "dropped" registers persistOnShutdown() and drops its last
 * reference to the repository before the end. Prints the pending writes before ending.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Repositories\File\InMemoryAndFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Tests\Support\WriteBehindEntity;

[, $path, $mode, $count] = $argv + [null, null, 'shutdown', '3'];
if ($path === null) {
    fwrite(STDERR, "usage: write_behind_child.php <storage-path> <mode> <put-count>\n");
    exit(2);
}

$repository = InMemoryAndFileRepository::create(
    $path,
    new JsonEntitySerializer(WriteBehindEntity::class),
    persistenceDefinition: PersistenceDefinition::MemoryAsyncFile
);
if ($mode !== 'none') {
    $repository->persistOnShutdown();
    $repository->persistOnShutdown(); // registered once
}
for ($i = 1; $i <= (int) $count; $i++) {
    $repository->put(new WriteBehindEntity("child-{$i}", $i));
}
echo $repository->pendingWrites(), "\n";

if ($mode === 'kill') {
    posix_kill(getmypid(), SIGKILL);
}
if ($mode === 'dropped') {
    $repository = null;
}
