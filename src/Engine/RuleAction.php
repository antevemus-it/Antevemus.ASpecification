<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

/**
 * RuleAction - Ação Operacional Resultante da Violação de uma Regra de Negócio
 *
 * Define o comportamento operacional e a severidade associada à quebra de uma especificação,
 * espelhando as diretrizes de catálogo de regras (ex.: bloquear HTTP 403, emitir alerta ou apenas log).
 *
 * Funcionalidades:
 * - Enumeração tipada para ações operacionais (BLOCK, WARN, LOG)
 * - Métodos auxiliares de verificação de severidade (isBlocking, isWarning, isLogOnly)
 * - Conversor flexível a partir de strings com suporte a fallback
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum RuleAction: string
{
    case BLOCK = 'bloquear';
    case WARN = 'alertar';
    case LOG = 'apenas_log';

    /**
     * Verifica se a ação representa bloqueio impeditivo.
     *
     * @return bool
     */
    public function isBlocking(): bool
    {
        return $this === self::BLOCK;
    }

    /**
     * Verifica se a ação representa advertência/alerta não-impeditivo.
     *
     * @return bool
     */
    public function isWarning(): bool
    {
        return $this === self::WARN;
    }

    /**
     * Verifica se a ação representa apenas registro para fins de telemetria/auditoria.
     *
     * @return bool
     */
    public function isLogOnly(): bool
    {
        return $this === self::LOG;
    }

    /**
     * Cria ou resolve a ação a partir de uma string recebida, usando fallback padrão se inválida.
     *
     * @param string|null $action Texto da ação (ex: 'bloquear', 'alertar', 'apenas_log')
     * @param self $default Ação padrão caso o valor seja nulo ou desconhecido
     * @return self
     */
    public static function fromOrDefault(?string $action, self $default = self::BLOCK): self
    {
        if ($action === null || trim($action) === '') {
            return $default;
        }

        return self::tryFrom(strtolower(trim($action))) ?? $default;
    }
}
