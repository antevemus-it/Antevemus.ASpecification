<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractUUIDEntity;

/**
 * Entity shared by the multi-process file repository tests and the child
 * writer script (tests/Support/single_file_writer.php). It lives in its own
 * file so that both the parent test process and the child processes autoload
 * exactly the same class.
 */
class ChildWriterEntity extends AbstractUUIDEntity
{
    public function __construct(public string $label = 'item')
    {
        parent::__construct();
    }
}
