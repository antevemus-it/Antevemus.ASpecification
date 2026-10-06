<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IDateSpecificationFactory - Factory contract for date/time specifications
 *
 * Contract for factories creating specifications operating over DateTime/DateTimeImmutable objects.
 * Enables temporal comparisons and date range validations.
 *
 * Unlike the original Java implementation that handled date strings via SimpleDateFormat,
 * this PHP implementation utilizes native DateTimeInterface types for type-safety.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDateSpecificationFactory extends ISpecificationFactory
{
    /**
     * Creates a specification verifying if candidate date is earlier (before).
     *
     * Example:
     * <code>
     * $spec = $factory->before(new DateTime('2025-12-31'));
     * $spec->isSatisfiedBy(new DateTime('2025-01-01')); // true
     * $spec->isSatisfiedBy(new DateTime('2026-01-01')); // false
     * </code>
     *
     * @param \DateTimeInterface $date Upper bound date (exclusive)
     * @return ISpecification<\DateTimeInterface> Before date specification
     */
    public function before(\DateTimeInterface $date): ISpecification;

    /**
     * Creates a specification verifying if candidate date is earlier than a date string.
     *
     * Converts string to DateTime using specified format or common fallback formats.
     *
     * @param string $dateString Date string representation
     * @param string|null $format Optional date format
     * @return ISpecification<\DateTimeInterface> Before date specification
     * @throws \InvalidArgumentException If date string cannot be parsed
     */
    public function beforeString(string $dateString, ?string $format = null): ISpecification;

    /**
     * Creates a specification verifying if candidate date is later (after).
     *
     * Example:
     * <code>
     * $spec = $factory->after(new DateTime('2025-01-01'));
     * $spec->isSatisfiedBy(new DateTime('2025-12-31')); // true
     * $spec->isSatisfiedBy(new DateTime('2024-12-31')); // false
     * </code>
     *
     * @param \DateTimeInterface $date Lower bound date (exclusive)
     * @return ISpecification<\DateTimeInterface> After date specification
     */
    public function after(\DateTimeInterface $date): ISpecification;

    /**
     * Creates a specification verifying if candidate date is later than a date string.
     *
     * @param string $dateString Date string representation
     * @param string|null $format Optional date format
     * @return ISpecification<\DateTimeInterface> After date specification
     * @throws \InvalidArgumentException If date string cannot be parsed
     */
    public function afterString(string $dateString, ?string $format = null): ISpecification;

    /**
     * Creates a specification verifying if candidate date equals target date exactly.
     *
     * @param \DateTimeInterface $date Target date for equality comparison
     * @return ISpecification<\DateTimeInterface> Exact date equality specification
     */
    public function at(\DateTimeInterface $date): ISpecification;

    /**
     * Alias for at().
     *
     * @param \DateTimeInterface $date Target date for comparison
     * @return ISpecification<\DateTimeInterface>
     */
    public function atTheSameTimeAs(\DateTimeInterface $date): ISpecification;

    /**
     * Creates a specification verifying if candidate date is earlier than or equal to target date.
     *
     * @param \DateTimeInterface $date Upper bound date (inclusive)
     * @return ISpecification<\DateTimeInterface> Before-or-at date specification
     */
    public function beforeOrAt(\DateTimeInterface $date): ISpecification;

    /**
     * Alias for beforeOrAt().
     *
     * @param \DateTimeInterface $date Upper bound date (inclusive)
     * @return ISpecification<\DateTimeInterface>
     */
    public function beforeOrAtTheSameTimeAs(\DateTimeInterface $date): ISpecification;

    /**
     * Creates a specification verifying if candidate date is earlier than or equal to a date string.
     *
     * @param string $dateString Date string representation
     * @param string|null $format Optional date format
     * @return ISpecification<\DateTimeInterface> Before-or-at date specification
     */
    public function beforeOrAtString(string $dateString, ?string $format = null): ISpecification;

    /**
     * Creates a specification verifying if candidate date is later than or equal to target date.
     *
     * @param \DateTimeInterface $date Lower bound date (inclusive)
     * @return ISpecification<\DateTimeInterface> After-or-at date specification
     */
    public function afterOrAt(\DateTimeInterface $date): ISpecification;

    /**
     * Alias for afterOrAt().
     *
     * @param \DateTimeInterface $date Lower bound date (inclusive)
     * @return ISpecification<\DateTimeInterface>
     */
    public function afterOrAtTheSameTimeAs(\DateTimeInterface $date): ISpecification;

    /**
     * Creates a specification verifying if candidate date is later than or equal to a date string.
     *
     * @param string $dateString Date string representation
     * @param string|null $format Optional date format
     * @return ISpecification<\DateTimeInterface> After-or-at date specification
     */
    public function afterOrAtString(string $dateString, ?string $format = null): ISpecification;

    /**
     * Creates a specification verifying if candidate date is between two dates.
     *
     * @param \DateTimeInterface $start Start date bound (inclusive)
     * @param \DateTimeInterface $end End date bound (inclusive)
     * @return ISpecification<\DateTimeInterface> Date range specification
     */
    public function between(\DateTimeInterface $start, \DateTimeInterface $end): ISpecification;

    /**
     * Creates a specification verifying if candidate date is today.
     *
     * Compares date portion (year, month, day) ignoring time components.
     *
     * @return ISpecification<\DateTimeInterface> Today date specification
     */
    public function isToday(): ISpecification;

    /**
     * Creates a specification verifying if candidate date is in the past.
     *
     * @return ISpecification<\DateTimeInterface> Past date specification
     */
    public function isPast(): ISpecification;

    /**
     * Creates a specification verifying if candidate date is in the future.
     *
     * @return ISpecification<\DateTimeInterface> Future date specification
     */
    public function isFuture(): ISpecification;
}
