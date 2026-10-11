<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToMany;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * The `Article` class of README example 17.3 (EN/pt-BR), as written (renamed so that it cannot collide
 * with an application class; its target is ReadmeTag). Used by the transcription of example 17.3
 * (Module 26).
 *
 * @version 1.14.0
 */
#[Table('article')]
class ReadmeArticle extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    /** @var list<ReadmeTag> */
    #[ManyToMany(ReadmeTag::class, 'article_tag', joinColumn: 'article_id', inverseJoinColumn: 'tag_id')]
    public array $tags = [];

    public function __construct(#[Column] public string $title)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
