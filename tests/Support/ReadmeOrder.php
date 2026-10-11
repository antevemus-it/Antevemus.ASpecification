<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToOne;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\OneToMany;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

/**
 * The `Order` class of README example 17.3 (EN/pt-BR), as written (renamed so that it cannot collide
 * with an application class; it refers to ReadmeCustomer and ReadmeOrderLine). Used by the
 * transcription of example 17.3 (Module 26).
 *
 * @version 1.13.0
 */
#[Table('orders')]
#[SoftDelete('deleted_at')]
class ReadmeOrder extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column('customer_id')] public ?int $customerId = null;
    #[ManyToOne(ReadmeCustomer::class, 'customer_id')] public ?ReadmeCustomer $customer = null;

    /** @var list<ReadmeOrderLine> */
    #[OneToMany(ReadmeOrderLine::class, mappedBy: 'orderId', cascade: true)] public array $lines = [];

    public function __construct(#[Column] public string $number)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
