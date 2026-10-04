<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

/**
 * IDocumentPresenceEvaluator - Contrato para Verificação de Existência Documental
 *
 * Permite ao compilador documental consultar se determinado documento obrigatório
 * está anexado, aprovado ou presente no contexto do objeto em validação.
 *
 * Funcionalidades:
 * - Avaliação de presença documental sobre entidades de domínio ou estruturas de dados
 * - Suporte a contexto estendido (ex: competência de aluguel, data de validade)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDocumentPresenceEvaluator
{
    /**
     * Avalia se o documento identificado pelo código fornecido está presente e válido no alvo.
     *
     * @param object|array $target Objeto ou payload sob avaliação
     * @param string $codigoTipoDocumento Código técnico do tipo de documento
     * @param array<string, mixed> $context Metadados adicionais de contexto
     * @return bool
     */
    public function hasDocument(object|array $target, string $codigoTipoDocumento, array $context = []): bool;
}
