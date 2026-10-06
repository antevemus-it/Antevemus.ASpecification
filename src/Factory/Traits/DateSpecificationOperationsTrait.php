<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;
use DateTimeInterface;

/**
 * DateSpecificationOperationsTrait - Trait aggregating temporal operations and date comparison specifications.
 *
 * Provides delegation methods forwarding to the underlying date specification factory.
 *
 * Features:
 * - Absolute and relative date validation (isToday, isPast, isFuture)
 * - Temporal comparisons (before, after, between, atTheSameTimeAs)
 * - Flexible string date parsing
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait DateSpecificationOperationsTrait
{
    /**
     * Creates a specification verifying whether a date is prior to a given date string.
     *
     * Converts the string into a DateTime instance using the specified format or common fallback formats.
     *
     * @param string $dateString Date string to parse
     * @param string|null $format Explicit date format (default: tries common formats)
     * @return ISpecification<\DateTimeInterface> Prior date specification
     * @throws \InvalidArgumentException If the string cannot be parsed into a date
     */
    public function beforeString(string $dateString, ?string $format = null): ISpecification
    {
        return $this->dateFactory->beforeString($dateString, $format);
    }

    /**
     * Creates a specification verifying whether a date is after a given date string.
     *
     * @param string $dateString Date string to parse
     * @param string|null $format Explicit date format (default: tries common formats)
     * @return ISpecification<\DateTimeInterface> Posterior date specification
     * @throws \InvalidArgumentException If the string cannot be parsed into a date
     */
    public function afterString(string $dateString, ?string $format = null): ISpecification
    {
        return $this->dateFactory->afterString($dateString, $format);
    }

    /**
     * Creates a specification verifying whether a date is exactly equal to the target date.
     *
     * @param \DateTimeInterface $date Target date for equality comparison
     * @return ISpecification<\DateTimeInterface> Date equality specification
     */
    public function at(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->at($date);
    }

    /**
     * Alias for at().
     *
     * @param \DateTimeInterface $date Target date for equality comparison
     * @return ISpecification<\DateTimeInterface> Date equality specification
     */
    public function atTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->atTheSameTimeAs($date);
    }

    /**
     * Creates a specification verifying whether a date is prior to or equal to the target date.
     *
     * @param \DateTimeInterface $date Upper bound date (inclusive)
     * @return ISpecification<\DateTimeInterface> Prior or equal date specification
     */
    public function beforeOrAt(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->beforeOrAt($date);
    }

    /**
     * Alias for beforeOrAt().
     *
     * @param \DateTimeInterface $date Upper bound date (inclusive)
     * @return ISpecification<\DateTimeInterface>
     */
    public function beforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->beforeOrAtTheSameTimeAs($date);
    }

    /**
     * Alias for beforeOrAt(). Verifies whether the date is prior to or equal to the target date.
     *
     * @param \DateTimeInterface $date Target date bound
     * @return ISpecification<\DateTimeInterface>
     */
    public function isBeforeOrAt(DateTimeInterface $date): ISpecification
    {
        return $this->beforeOrAt($date);
    }

    /**
     * Alias for beforeOrAt(). Verifies whether the date is prior to or equal to the target date.
     *
     * @param \DateTimeInterface $date Target date bound
     * @return ISpecification<\DateTimeInterface>
     */
    public function isBeforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->beforeOrAtTheSameTimeAs($date);
    }

    /**
     * Alias for at(). Verifies whether the date is at the exact same point in time.
     *
     * @param \DateTimeInterface $date Target date for comparison
     * @return ISpecification<\DateTimeInterface>
     */
    public function isAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->atTheSameTimeAs($date);
    }

    /**
     * Alias for afterOrAt(). Verifies whether the date is posterior to or equal to the target date.
     *
     * @param \DateTimeInterface $date Target date bound
     * @return ISpecification<\DateTimeInterface>
     */
    public function isAfterOrAt(DateTimeInterface $date): ISpecification
    {
        return $this->afterOrAt($date);
    }

    /**
     * Alias for afterOrAt(). Verifies whether the date is posterior to or equal to the target date.
     *
     * @param \DateTimeInterface $date Target date bound
     * @return ISpecification<\DateTimeInterface>
     */
    public function isAfterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->afterOrAtTheSameTimeAs($date);
    }

    /**
     * Creates a specification verifying whether a date is prior to or equal to a date string.
     *
     * @param string $dateString Date string to parse
     * @param string|null $format Explicit date format (default: tries common formats)
     * @return ISpecification<\DateTimeInterface> Prior or equal date specification
     */
    public function beforeOrAtString(string $dateString, ?string $format = null): ISpecification
    {
        return $this->dateFactory->beforeOrAtString($dateString, $format);
    }

    /**
     * Creates a specification verifying whether a date is posterior to or equal to the target date.
     *
     * @param \DateTimeInterface $date Lower bound date (inclusive)
     * @return ISpecification<\DateTimeInterface> Posterior or equal date specification
     */
    public function afterOrAt(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->afterOrAt($date);
    }

    /**
     * Alias for afterOrAt().
     *
     * @param \DateTimeInterface $date Lower bound date (inclusive)
     * @return ISpecification<\DateTimeInterface>
     */
    public function afterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->afterOrAtTheSameTimeAs($date);
    }

    /**
     * Creates a specification verifying whether a date is posterior to or equal to a date string.
     *
     * @param string $dateString Date string to parse
     * @param string|null $format Explicit date format (default: tries common formats)
     * @return ISpecification<\DateTimeInterface> Posterior or equal date specification
     */
    public function afterOrAtString(string $dateString, ?string $format = null): ISpecification
    {
        return $this->dateFactory->afterOrAtString($dateString, $format);
    }

    /**
     * Creates a specification verifying whether a date is within an inclusive range between two dates.
     *
     * @param \DateTimeInterface $start Start date bound (inclusive)
     * @param \DateTimeInterface $end End date bound (inclusive)
     * @return ISpecification<\DateTimeInterface> Range specification
     */
    public function between(DateTimeInterface $start, DateTimeInterface $end): ISpecification
    {
        return $this->dateFactory->between($start, $end);
    }

    /**
     * Creates a specification verifying whether a date falls on today's calendar day.
     *
     * Compares year, month, and day while ignoring time components.
     *
     * @return ISpecification<\DateTimeInterface> Today specification
     */
    public function isToday(): ISpecification
    {
        return $this->dateFactory->isToday();
    }

    /**
     * Creates a specification verifying whether a date is in the past.
     *
     * @return ISpecification<\DateTimeInterface> Past date specification
     */
    public function isPast(): ISpecification
    {
        return $this->dateFactory->isPast();
    }

    /**
     * Creates a specification verifying whether a date is in the future.
     *
     * @return ISpecification<\DateTimeInterface> Future date specification
     */
    public function isFuture(): ISpecification
    {
        return $this->dateFactory->isFuture();
    }

}
