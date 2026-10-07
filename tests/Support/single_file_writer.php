<?php

declare(strict_types=1);

/**
 * Child writer used by the multi-process regression test of SingleFileRepository
 * (BUG-20261007-7RZJ, lost update between processes).
 *
 * Usage: php single_file_writer.php <storage-path> <writer-id> <put-count>
 *
 * Opens the repository in ReadWrite mode and performs <put-count> sequential
 * put() calls, each one of a brand-new entity. Prints the number of puts done.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Repositories\File\SingleFileRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Tests\Support\ChildWriterEntity;

$path = $argv[1] ?? null;
$writerId = $argv[2] ?? 'w';
$count = (int) ($argv[3] ?? 5);

if ($path === null) {
    fwrite(STDERR, "usage: single_file_writer.php <storage-path> <writer-id> <put-count>\n");
    exit(2);
}

$repo = new SingleFileRepository(
    $path,
    ChildWriterEntity::class,
    PersistenceDefinition::ReadWrite,
    new JsonEntitySerializer(ChildWriterEntity::class)
);

for ($i = 1; $i <= $count; $i++) {
    $repo->put(new ChildWriterEntity("{$writerId}-{$i}"));
}

echo $count, "\n";
