<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Results;

use Stringable;

/**
 * SpecificationFailure - Objeto de Notificação para Registro Detalhado de Violação de Regra
 *
 * Representa uma falha pontual decorrente da avaliação de uma especificação no contexto
 * do Notification Pattern (Martin Fowler). Carrega a mensagem descritiva de erro,
 * o código de negócio/regulatório, o nome da regra violada, a propriedade afetada e metadados.
 *
 * Funcionalidades:
 * - Estrutura imutável (readonly) com tipagem estrita
 * - Suporte a códigos de erro regulatórios (ex: 'INQ_004', 'APOL_002')
 * - Associação com a propriedade do objeto candidata à validação
 * - Associação de metadados contextuais para rastreabilidade e depuração
 * - Representação textual amigável via Stringable
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Results
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class SpecificationFailure implements Stringable
{
    /**
     * @param string $message Mensagem amigável explicando o motivo da violação
     * @param string|null $code Código identificador do erro (ex.: código de artigo legal ou regra de domínio)
     * @param string|null $ruleName Nome da classe ou identificador da especificação que falhou
     * @param string|null $property Nome da propriedade do objeto avaliado que violou a regra
     * @param array<string, mixed> $metadata Dados complementares de contexto (valores recebidos, limites, etc.)
     */
    public function __construct(
        public string $message,
        public ?string $code = null,
        public ?string $ruleName = null,
        public ?string $property = null,
        public array $metadata = []
    ) {
    }

    /**
     * Retorna uma nova instância com a propriedade associada definida.
     *
     * @param string $property Nome da propriedade do objeto avaliado
     * @return self
     */
    public function withProperty(string $property): self
    {
        return new self(
            message: $this->message,
            code: $this->code,
            ruleName: $this->ruleName,
            property: $property,
            metadata: $this->metadata
        );
    }

    /**
     * Retorna representação textual amigável da falha.
     *
     * @return string
     */
    public function __toString(): string
    {
        $prefix = $this->code !== null ? "[{$this->code}] " : "";
        $prop = $this->property !== null ? " (propriedade '{$this->property}')" : "";
        return "{$prefix}{$this->message}{$prop}";
    }
}
