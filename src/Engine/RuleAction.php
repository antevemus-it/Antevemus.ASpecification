<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Results\FailureSeverity;

/**
 * RuleAction - Operational Action Resulting from a Business Rule Violation
 *
 * Defines the operational behavior and severity associated with a specification violation,
 * mirroring rule catalog guidelines (e.g. HTTP 403 block, issue warning, or log only).
 *
 * Features:
 * - Typed enumeration for operational actions (BLOCK, WARN, LOG)
 * - Helper methods for severity checking (isBlocking, isWarning, isLogOnly)
 * - Flexible string converter with fallback support
 * - Mapping to the FailureSeverity carried by engine failures (toSeverity, 1.5.0)
 * - English values since 1.7.0 (`block`, `warn`, `log`); the Portuguese values of 1.6.x
 *   (`bloquear`, `alertar`, `apenas_log`) are still read by fromOrDefault() (legacy)
 *
 * @version    1.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum RuleAction: string
{
    case BLOCK = 'block';
    case WARN = 'warn';
    case LOG = 'log';

    /**
     * Portuguese values used up to 1.6.x, mapped to the canonical value. Accepted on read by
     * fromOrDefault() (catalog rows and failure metadata written before 1.7.0); never produced.
     * Legacy since 1.7.0, removed in 2.0.0: store the English values (block, warn, log).
     */
    public const LEGACY_PORTUGUESE_VALUES = [
        'bloquear' => 'block',
        'alertar' => 'warn',
        'apenas_log' => 'log',
    ];

    /**
     * Checks if the action represents an impeding block.
     *
     * @return bool
     */
    public function isBlocking(): bool
    {
        return $this === self::BLOCK;
    }

    /**
     * Checks if the action represents a non-impeding advisory/warning.
     *
     * @return bool
     */
    public function isWarning(): bool
    {
        return $this === self::WARN;
    }

    /**
     * Checks if the action represents an audit-only/log recording.
     *
     * @return bool
     */
    public function isLogOnly(): bool
    {
        return $this === self::LOG;
    }

    /**
     * Maps the action to the severity stamped on the failures it produces:
     * BLOCK → ERROR, WARN → WARNING, LOG → INFO.
     *
     * @return FailureSeverity
     */
    public function toSeverity(): FailureSeverity
    {
        return match ($this) {
            self::BLOCK => FailureSeverity::ERROR,
            self::WARN => FailureSeverity::WARNING,
            self::LOG => FailureSeverity::INFO,
        };
    }

    /**
     * Resolves the action from an input string, using the default fallback if invalid.
     *
     * Accepts the canonical values (`block`, `warn`, `log`) and, as legacy, the Portuguese values of
     * 1.6.x (`bloquear`, `alertar`, `apenas_log`), case-insensitively.
     *
     * @param string|null $action Action string (e.g. 'block', 'warn', 'log'; legacy 'bloquear', 'alertar', 'apenas_log')
     * @param self $default Default action if value is null or unrecognized
     * @return self
     */
    public static function fromOrDefault(?string $action, self $default = self::BLOCK): self
    {
        if ($action === null || trim($action) === '') {
            return $default;
        }

        $key = strtolower(trim($action));
        $legacy = self::LEGACY_PORTUGUESE_VALUES[$key] ?? null;

        return self::tryFrom($key) ?? ($legacy !== null ? self::from($legacy) : $default);
    }
}
