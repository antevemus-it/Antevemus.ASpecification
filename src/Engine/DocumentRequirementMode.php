<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

/**
 * DocumentRequirementMode - Modo de Obrigatoriedade de Documento em Grupo Operacional
 *
 * Define a semântica de validação documental para conjuntos de requisitos em operações de domínio.
 * Suporta exigência universal (all), disjunção alternativa (any) ou exclusividade estrita (one_of_set).
 *
 * Funcionalidades:
 * - Enumeração tipada para regras de documentos (ALL, ANY, ONE_OF_SET)
 * - Resolução segura a partir de strings com valor padrão configurável
 * - Suporte à álgebra de especificações booleanas (And, Or, Xor)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum DocumentRequirementMode: string
{
    case ALL = 'all';
    case ANY = 'any';
    case ONE_OF_SET = 'one_of_set';

    /**
     * Cria ou resolve o modo a partir de uma string recebida, usando fallback padrão se inválido.
     *
     * @param string|null $mode Texto da regra (ex: 'all', 'any', 'one_of_set')
     * @param self $default Modo padrão caso o valor seja nulo ou desconhecido
     * @return self
     */
    public static function fromOrDefault(?string $mode, self $default = self::ALL): self
    {
        if ($mode === null || trim($mode) === '') {
            return $default;
        }

        return self::tryFrom(strtolower(trim($mode))) ?? $default;
    }
}
