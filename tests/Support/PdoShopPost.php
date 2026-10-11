<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToMany;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * Owner of the ManyToMany fixture of Module 26 (forward 032, D1): posts linked to tags through
 * aspec_rel_post_tag (post_id, tag_id); soft delete keeps the links, a hard removal deletes them.
 *
 * @version 1.14.0
 */
#[Table('aspec_rel_post')]
#[SoftDelete('deleted_at')]
class PdoShopPost extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    #[Column('id')]
    public ?int $id = null;

    #[Column('title')]
    public string $title;

    /** @var list<PdoShopTag> Association: only the links are written */
    #[ManyToMany(PdoShopTag::class, 'aspec_rel_post_tag', joinColumn: 'post_id', inverseJoinColumn: 'tag_id')]
    public array $tags = [];

    /**
     * @param list<PdoShopTag> $tags
     */
    public function __construct(string $title, array $tags = [])
    {
        parent::__construct();
        $this->title = $title;
        $this->tags = $tags;
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
