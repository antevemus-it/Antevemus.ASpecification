<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;

/**
 * The `Policy` class of README example 18 (EN/pt-BR), as written (renamed so that it cannot collide
 * with an application class). Used by the transcription of example 18 (Module 23).
 *
 * @version 1.11.0
 */
class ReadmePolicy extends AbstractEntity
{
    public int $daysOverdue = 0;
    public bool $documentsValidated = false;
    public string $holderName = 'Maria Silva';

    public function __construct(private string $number)
    {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->number;
    }
}
