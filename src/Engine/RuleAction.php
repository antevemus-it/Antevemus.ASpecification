<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException;
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
 *   (`bloquear`, `alertar`, `apenas_log`), read as legacy up to 1.14.x, are refused since 2.0.0 with
 *   LegacyVocabularyException (never mapped to the default action in silence)
 * - fromMetadata() (2.0.0): the action recorded in failure metadata, shared by the engine and the verdict
 *
 * @version    2.0.0
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
     * Portuguese values used up to 1.6.x (legacy => canonical). Read as legacy from 1.7.0 to 1.14.x;
     * since 2.0.0 fromOrDefault() refuses them with LegacyVocabularyException: store the English values
     * (block, warn, log), see the UPDATE statements of UPGRADE-2.0.md.
     */
    public const LEGACY_PORTUGUESE_VALUES = [
        'bloquear' => 'block',
        'alertar' => 'warn',
        'apenas_log' => 'log',
    ];

    /**
     * Portuguese metadata keys that carried the action up to 1.14.x (legacy => canonical). A failure
     * whose metadata still uses one is refused by fromMetadata() since 2.0.0.
     */
    public const LEGACY_METADATA_KEYS = [
        'acao' => 'action',
        'acao_ao_violar' => 'violation_action',
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
     * Resolves the action from an input string, using the default fallback if null or empty.
     *
     * Accepts the canonical values (`block`, `warn`, `log`), case-insensitively. A Portuguese value of
     * 1.6.x (`bloquear`, `alertar`, `apenas_log`) is refused since 2.0.0: mapping it to the default would
     * turn a warning into a block (or the opposite) without anyone noticing. Any other unknown value
     * falls back to the default, as before.
     *
     * @param string|null $action Action string ('block', 'warn', 'log')
     * @param self $default Default action if value is null, empty or unrecognized
     * @return self
     * @throws LegacyVocabularyException When the value is one of the Portuguese values of 1.6.x
     */
    public static function fromOrDefault(?string $action, self $default = self::BLOCK): self
    {
        if ($action === null || trim($action) === '') {
            return $default;
        }

        $key = strtolower(trim($action));
        if (isset(self::LEGACY_PORTUGUESE_VALUES[$key])) {
            throw new LegacyVocabularyException($action, self::LEGACY_PORTUGUESE_VALUES[$key], 'violation action value');
        }

        return self::tryFrom($key) ?? $default;
    }

    /**
     * Resolves the action recorded in the metadata of a failure: `action`, then `violation_action`
     * (a RuleAction or its value), the default when neither is present. Shared by the engine (failure
     * stamping and severity) and RuleEngineVerdict, so that severity and triage never disagree.
     *
     * @param array<string, mixed> $metadata Failure metadata
     * @param self $default Action when the metadata records none
     * @return self
     * @throws LegacyVocabularyException When the metadata uses a Portuguese key of 1.x (`acao`,
     *                                   `acao_ao_violar`) or a Portuguese action value
     */
    public static function fromMetadata(array $metadata, self $default = self::BLOCK): self
    {
        LegacyVocabularyException::assertNoLegacyKeys($metadata, self::LEGACY_METADATA_KEYS, 'failure metadata key');

        $raw = $metadata['action'] ?? $metadata['violation_action'] ?? null;
        if ($raw instanceof self) {
            return $raw;
        }

        return is_string($raw) ? self::fromOrDefault($raw, $default) : $default;
    }
}
