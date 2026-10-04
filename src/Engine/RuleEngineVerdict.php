<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Countable;

/**
 * RuleEngineVerdict - Veredito Operacional Estruturado da Dynamic Rule Engine
 *
 * Agrega o veredito emitido pela avaliação de regras de negócio e documentos,
 * particionando as falhas de acordo com suas ações operacionais (bloqueios HTTP 403, alertas e logs).
 *
 * Funcionalidades:
 * - Classificação automática de violações por severidade (Blocking, Warning, Log)
 * - Consulta de aprovação global (isSatisfied) e verificação de impedimentos (hasBlockingErrors)
 * - Extração direta de códigos de violação, mensagens amigáveis e fundamentos legais
 * - Encapsulamento transparente do SpecificationResult original
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class RuleEngineVerdict implements Countable
{
    /**
     * @param bool $isSatisfied True se nenhuma falha foi registrada
     * @param list<SpecificationFailure> $blockingFailures Violações com ação 'bloquear'
     * @param list<SpecificationFailure> $warningFailures Violações com ação 'alertar'
     * @param list<SpecificationFailure> $logFailures Violações com ação 'apenas_log'
     * @param SpecificationResult $specificationResult Resultado original do Notification Pattern
     */
    public function __construct(
        public bool $isSatisfied,
        public array $blockingFailures,
        public array $warningFailures,
        public array $logFailures,
        public SpecificationResult $specificationResult
    ) {
    }

    /**
     * Cria um veredito aprovado sem violações.
     *
     * @return self
     */
    public static function satisfied(): self
    {
        $specResult = SpecificationResult::satisfied();
        return new self(
            isSatisfied: true,
            blockingFailures: [],
            warningFailures: [],
            logFailures: [],
            specificationResult: $specResult
        );
    }

    /**
     * Constrói o veredito operacional particionando as falhas do SpecificationResult por ação.
     *
     * @param SpecificationResult $result
     * @return self
     */
    public static function fromSpecificationResult(SpecificationResult $result): self
    {
        if ($result->isSatisfied) {
            return self::satisfied();
        }

        $blocking = [];
        $warning = [];
        $log = [];

        foreach ($result->failures as $failure) {
            $actionRaw = $failure->metadata['acao']
                ?? $failure->metadata['acao_ao_violar']
                ?? $failure->metadata['action']
                ?? 'bloquear';

            $action = $actionRaw instanceof RuleAction
                ? $actionRaw
                : RuleAction::fromOrDefault(is_string($actionRaw) ? $actionRaw : null);

            match ($action) {
                RuleAction::BLOCK => $blocking[] = $failure,
                RuleAction::WARN => $warning[] = $failure,
                RuleAction::LOG => $log[] = $failure,
            };
        }

        return new self(
            isSatisfied: $result->isSatisfied,
            blockingFailures: $blocking,
            warningFailures: $warning,
            logFailures: $log,
            specificationResult: $result
        );
    }

    /**
     * Verifica se todas as regras foram cumpridas com sucesso.
     *
     * @return bool
     */
    public function isSatisfied(): bool
    {
        return $this->isSatisfied;
    }

    /**
     * Verifica se existem violações de bloqueio que impedem a transição de estado.
     *
     * @return bool
     */
    public function hasBlockingErrors(): bool
    {
        return count($this->blockingFailures) > 0;
    }

    /**
     * Verifica se existem advertências que exigem ciência do usuário.
     *
     * @return bool
     */
    public function hasWarnings(): bool
    {
        return count($this->warningFailures) > 0;
    }

    /**
     * Verifica se existem registros exclusivos de auditoria/log.
     *
     * @return bool
     */
    public function hasLogs(): bool
    {
        return count($this->logFailures) > 0;
    }

    /**
     * Retorna todas as falhas com ação 'bloquear'.
     *
     * @return list<SpecificationFailure>
     */
    public function getBlockingFailures(): array
    {
        return $this->blockingFailures;
    }

    /**
     * Retorna todas as falhas com ação 'alertar'.
     *
     * @return list<SpecificationFailure>
     */
    public function getWarningFailures(): array
    {
        return $this->warningFailures;
    }

    /**
     * Retorna todas as falhas com ação 'apenas_log'.
     *
     * @return list<SpecificationFailure>
     */
    public function getLogFailures(): array
    {
        return $this->logFailures;
    }

    /**
     * Retorna todas as falhas registradas, independente da ação.
     *
     * @return list<SpecificationFailure>
     */
    public function getAllFailures(): array
    {
        return $this->specificationResult->failures;
    }

    /**
     * Retorna os códigos de negócio ou regulatórios de todas as falhas registradas.
     *
     * @return list<string>
     */
    public function getFailureCodes(): array
    {
        return $this->specificationResult->getCodes();
    }

    /**
     * Retorna as mensagens explicativas de todas as falhas registradas.
     *
     * @return list<string>
     */
    public function getMessages(): array
    {
        return $this->specificationResult->getReasons();
    }

    /**
     * Retorna a lista de fundamentos legais extraídos dos metadados das falhas.
     *
     * @return list<string>
     */
    public function getLegalBases(): array
    {
        $bases = [];
        foreach ($this->specificationResult->failures as $failure) {
            $base = $failure->metadata['fundamento_legal']
                ?? $failure->metadata['legal_basis']
                ?? null;

            if (is_string($base) && trim($base) !== '' && !in_array($base, $bases, true)) {
                $bases[] = $base;
            }
        }
        return $bases;
    }

    /**
     * Retorna o total de violações registradas.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->specificationResult);
    }
}
