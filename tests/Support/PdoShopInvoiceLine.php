<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;

/**
 * Invoice line of the relationship tests (forward 031, E3, D7), mapped with the builder: a Max
 * identifier, the invoice code as a plain foreign key property (mappedBy a scalar), no soft delete.
 *
 * @version 1.13.0
 */
class PdoShopInvoiceLine extends AbstractEntity
{
    public ?int $id = null;

    public ?string $invoiceCode = null;

    public string $description;

    public function __construct(string $description)
    {
        parent::__construct();
        $this->description = $description;
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}
