<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

/**
 * LegacyVocabularyException - Portuguese Vocabulary of the Rule Engine Refused Since 2.0.0
 *
 * The rule engine adopted English in 1.7.0 and kept reading the Portuguese vocabulary of 1.6.x as
 * legacy until 2.0.0: catalog keys (`codigo`, `acao_ao_violar`...), action values (`bloquear`,
 * `alertar`, `apenas_log`), applicability, filter and context keys (`codigo_produto`...), table roles
 * (`regra`, `grupo`, `grupo_tipo`), metadata keys of failures (`acao`) and the Portuguese shape of a
 * document holder (`documentos`). Since 2.0.0 a Portuguese name or value is refused with this
 * exception, which names it, gives the English replacement and points to the UPDATE statements of
 * UPGRADE-2.0.md. It is never ignored in silence: a value that is not read changes which rules apply
 * or how a violation is triaged.
 *
 * Features:
 * - The Portuguese name or value, its English replacement and where it was found
 * - A message that points to UPGRADE-2.0.md ("Rule engine: Portuguese vocabulary")
 *
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class LegacyVocabularyException extends RuleEngineException
{
    /** Upgrade guide quoted by the message. */
    public const UPGRADE_GUIDE = 'UPGRADE-2.0.md';

    /**
     * @param string $legacy Portuguese name or value found
     * @param string $replacement English name or value that replaced it in 1.7.0
     * @param string $where Where it was found (e.g. 'rule catalog row "R1" key', 'violation action value')
     */
    public function __construct(
        public readonly string $legacy,
        public readonly string $replacement,
        public readonly string $where
    ) {
        parent::__construct(sprintf(
            '%s "%s" is Portuguese vocabulary of the rule engine, renamed "%s" in 1.7.0 and no longer read since 2.0.0. '
            . 'Migrate the catalog (or the calling code) to the English vocabulary: see %s, "Rule engine: Portuguese vocabulary".',
            ucfirst($where),
            $legacy,
            $replacement,
            self::UPGRADE_GUIDE
        ));
    }

    /**
     * Refuses the first key of $data that is a Portuguese name of the map (legacy => English).
     *
     * @param array<array-key, mixed> $data Row, parameters, filters or metadata to check
     * @param array<string, string> $legacyKeys Portuguese name => English name
     * @param string $where Description of $data used in the message
     * @return void
     * @throws self
     */
    public static function assertNoLegacyKeys(array $data, array $legacyKeys, string $where): void
    {
        foreach ($legacyKeys as $legacy => $replacement) {
            if (array_key_exists($legacy, $data)) {
                throw new self($legacy, $replacement, $where);
            }
        }
    }
}
