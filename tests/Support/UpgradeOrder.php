<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use DateTimeImmutable;

/**
 * Entity of the 2.0.0 upgrade fixtures (tests/Fixtures/file_repository/): written by the real
 * v1.14.0 with PhpNativeEntitySerializer (`.bin`), converted to JSON on v1.14.0 by the recipe of
 * UPGRADE-2.0.md and read by 2.0.0. It carries what the JSON serializer has to keep: private and
 * readonly properties, a date and a list. It lives in its own file so that the generator run on
 * v1.14.0 and the suite load exactly the same class.
 */
class UpgradeOrder extends AbstractUUIDEntity
{
    /**
     * @param string $customer Customer name
     * @param int $amountCents Order amount in cents
     * @param DateTimeImmutable $placedAt Order date
     * @param list<string> $tags Free labels
     */
    public function __construct(
        private string $customer,
        private readonly int $amountCents,
        private DateTimeImmutable $placedAt,
        public array $tags = []
    ) {
        parent::__construct();
    }

    public function getCustomer(): string
    {
        return $this->customer;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getPlacedAt(): DateTimeImmutable
    {
        return $this->placedAt;
    }
}
