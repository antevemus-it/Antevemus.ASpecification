<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractEntity;

/**
 * Invoice of the relationship tests (forward 031, E3, D7), WITHOUT attributes (mapped with the
 * EntityMapping builder): an assigned code, a soft delete mark, a cascaded collection of lines
 * (without soft delete: kept when the invoice is soft-removed) and one of payments (with soft delete:
 * marked with it).
 *
 * @version 1.13.0
 */
class PdoShopInvoice extends AbstractEntity
{
    public string $code;

    public string $customerName;

    /** @var list<PdoShopInvoiceLine> */
    public array $lines = [];

    /** @var list<PdoShopInvoicePayment> */
    public array $payments = [];

    /**
     * @param list<PdoShopInvoiceLine> $lines
     * @param list<PdoShopInvoicePayment> $payments
     */
    public function __construct(string $code, string $customerName, array $lines = [], array $payments = [])
    {
        parent::__construct();
        $this->code = $code;
        $this->customerName = $customerName;
        $this->lines = $lines;
        $this->payments = $payments;
    }

    public function getEntityId(): string
    {
        return $this->code;
    }
}
