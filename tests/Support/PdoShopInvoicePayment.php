<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;

/**
 * Invoice payment of the relationship tests (forward 031, E3, D7), mapped with the builder: a ULID
 * identifier, the invoice code as foreign key, a soft delete column without property, and a positive
 * amount enforced by the database (CHECK), to make a child write fail.
 *
 * @version 1.13.0
 */
class PdoShopInvoicePayment extends AbstractEntity
{
    public ?string $id = null;

    public ?string $invoiceCode = null;

    public int $cents;

    public function __construct(int $cents)
    {
        parent::__construct();
        $this->cents = $cents;
    }

    public function getEntityId(): ?string
    {
        return $this->id;
    }
}
