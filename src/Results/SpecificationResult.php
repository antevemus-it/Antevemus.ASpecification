<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Results;

use Countable;
use Stringable;

/**
 * SpecificationResult - Objeto de Resultado Rico e Notificação de Avaliação de Especificação
 *
 * Implementa o Notification Pattern (Martin Fowler) e o Result Object Pattern para encapsular
 * o veredito completo de avaliação de uma especificação ou de uma árvore de regras combinadas.
 * Fornece acesso ao status de aprovação booleana e à coleção completa de falhas registradas.
 *
 * Funcionalidades:
 * - Indicador de aprovação lógica (isSatisfied)
 * - Agregação imutável de falhas (failures) do tipo SpecificationFailure
 * - Fábricas estáticas expressivas (satisfied, failure, combine)
 * - Extração direta de motivos amigáveis (getReasons) e códigos regulatórios (getCodes)
 * - Consulta rápida por código de erro (hasError) ou por propriedade afetada (getFailuresForProperty)
 * - Implementação de Countable e Stringable para integração idiomática
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Results
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class SpecificationResult implements Countable, Stringable
{
    /**
     * @param bool $isSatisfied True se todas as regras foram cumpridas, false se houve violação
     * @param list<SpecificationFailure> $failures Coleção de falhas registradas
     */
    public function __construct(
        public bool $isSatisfied,
        public array $failures = []
    ) {
    }

    /**
     * Cria um resultado de aprovação sem falhas.
     *
     * @return self
     */
    public static function satisfied(): self
    {
        return new self(true, []);
    }

    /**
     * Cria um resultado de reprovação com uma falha pontual.
     *
     * @param string $message Mensagem descritiva da falha
     * @param string|null $code Código de negócio/regulatório
     * @param string|null $ruleName Nome da classe ou identificador da especificação
     * @param string|null $property Propriedade inspecionada
     * @param array<string, mixed> $metadata Metadados contextuais adicionais
     * @return self
     */
    public static function failure(
        string $message,
        ?string $code = null,
        ?string $ruleName = null,
        ?string $property = null,
        array $metadata = []
    ): self {
        return new self(false, [
            new SpecificationFailure($message, $code, $ruleName, $property, $metadata)
        ]);
    }

    /**
     * Combina múltiplos resultados em um único resultado consolidado.
     * O resultado consolidado só será considerado satisfeito se TODOS os resultados forem satisfeitos.
     *
     * @param self ...$results Resultados a serem combinados
     * @return self
     */
    public static function combine(self ...$results): self
    {
        $isSatisfied = true;
        $failures = [];

        foreach ($results as $result) {
            if (!$result->isSatisfied) {
                $isSatisfied = false;
            }
            foreach ($result->failures as $failure) {
                $failures[] = $failure;
            }
        }

        return new self($isSatisfied, $failures);
    }

    /**
     * Retorna a lista contendo apenas as mensagens amigáveis de erro.
     *
     * @return list<string>
     */
    public function getReasons(): array
    {
        return array_map(
            static fn(SpecificationFailure $failure): string => $failure->message,
            $this->failures
        );
    }

    /**
     * Retorna a lista contendo todos os códigos de erro associados às falhas.
     *
     * @return list<string>
     */
    public function getCodes(): array
    {
        $codes = [];
        foreach ($this->failures as $failure) {
            if ($failure->code !== null && $failure->code !== '') {
                $codes[] = $failure->code;
            }
        }
        return $codes;
    }

    /**
     * Verifica se o resultado contém falha, opcionalmente filtrando por código específico.
     *
     * @param string|null $code Código do erro a consultar (null para verificar se há qualquer falha)
     * @return bool
     */
    public function hasError(?string $code = null): bool
    {
        if ($this->isSatisfied) {
            return false;
        }

        if ($code === null) {
            return !empty($this->failures);
        }

        foreach ($this->failures as $failure) {
            if ($failure->code === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retorna apenas as falhas associadas a uma determinada propriedade.
     *
     * @param string $property Nome da propriedade
     * @return list<SpecificationFailure>
     */
    public function getFailuresForProperty(string $property): array
    {
        return array_values(
            array_filter(
                $this->failures,
                static fn(SpecificationFailure $f): bool => $f->property === $property
            )
        );
    }

    /**
     * Retorna o total de falhas contidas no resultado.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->failures);
    }

    /**
     * Retorna representação textual amigável do resultado.
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->isSatisfied) {
            return "Satisfied";
        }

        return implode("; ", $this->getReasons());
    }
}
