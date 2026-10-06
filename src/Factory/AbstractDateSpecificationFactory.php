<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\IDateSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractDateSpecificationFactory - Base abstract factory for date specifications
 *
 * Provides default alias implementations and helper routines for parsing
 * date strings across multiple common PHP formats.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractDateSpecificationFactory implements IDateSpecificationFactory
{
    /**
     * Common date formats attempted during string parsing.
     *
     * @var array<string>
     */
    protected array $commonDateFormats = [
        'Y-m-d',           // 2025-01-15
        'd/m/Y',           // 15/01/2025
        'd-m-Y',           // 15-01-2025
        'd.m.Y',           // 15.01.2025
        'Y/m/d',           // 2025/01/15
        'Ymd',             // 20250115
        'Y-m-d H:i:s',     // 2025-01-15 14:30:00
        'd/m/Y H:i:s',     // 15/01/2025 14:30:00
        \DateTime::ATOM,   // 2025-01-15T14:30:00+00:00
        \DateTime::ISO8601,
        \DateTime::RFC3339,
    ];

    /**
     * Converts date string into a DateTimeImmutable object.
     *
     * Helper method attempting parsing using specified format or common fallback formats.
     *
     * @param string $dateString Date string representation
     * @param string|null $format Specific format or null to try common formats
     * @return \DateTimeImmutable Parsed date object
     * @throws \InvalidArgumentException If date string cannot be parsed
     */
    protected function parseDateString(string $dateString, ?string $format = null): \DateTimeImmutable
    {
        if (empty($dateString)) {
            throw new \InvalidArgumentException('Date string cannot be empty');
        }

        // If specific format is provided, use only that
        if ($format !== null) {
            $date = \DateTimeImmutable::createFromFormat($format, $dateString);
            if ($date === false) {
                throw new \InvalidArgumentException(
                    "Unable to parse date string '{$dateString}' with format '{$format}'"
                );
            }
            return $date;
        }

        // Try common formats
        foreach ($this->commonDateFormats as $tryFormat) {
            $date = \DateTimeImmutable::createFromFormat($tryFormat, $dateString);
            if ($date !== false) {
                return $date;
            }
        }

        // Final attempt: standard constructor
        try {
            return new \DateTimeImmutable($dateString);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Unable to parse date string '{$dateString}'. Tried common formats and strtotime.",
                0,
                $e
            );
        }
    }

    /**
     * Validates a DateTime object.
     *
     * @param \DateTimeInterface $date Date to validate
     * @throws \InvalidArgumentException If date is null
     */
    protected function validateDate(\DateTimeInterface $date): void
    {
        if ($date === null) {
            throw new \InvalidArgumentException('Date cannot be null');
        }
    }

    /**
     * {@inheritdoc}
     */
    abstract public function before(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function beforeString(string $dateString, ?string $format = null): ISpecification
    {
        $date = $this->parseDateString($dateString, $format);
        return $this->before($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function after(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function afterString(string $dateString, ?string $format = null): ISpecification
    {
        $date = $this->parseDateString($dateString, $format);
        return $this->after($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function at(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function atTheSameTimeAs(\DateTimeInterface $date): ISpecification
    {
        return $this->at($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function beforeOrAt(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function beforeOrAtTheSameTimeAs(\DateTimeInterface $date): ISpecification
    {
        return $this->beforeOrAt($date);
    }

    /**
     * {@inheritdoc}
     */
    public function beforeOrAtString(string $dateString, ?string $format = null): ISpecification
    {
        $date = $this->parseDateString($dateString, $format);
        return $this->beforeOrAt($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function afterOrAt(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function afterOrAtTheSameTimeAs(\DateTimeInterface $date): ISpecification
    {
        return $this->afterOrAt($date);
    }

    /**
     * {@inheritdoc}
     */
    public function afterOrAtString(string $dateString, ?string $format = null): ISpecification
    {
        $date = $this->parseDateString($dateString, $format);
        return $this->afterOrAt($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function between(\DateTimeInterface $start, \DateTimeInterface $end): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isToday(): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isPast(): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isFuture(): ISpecification;
}
