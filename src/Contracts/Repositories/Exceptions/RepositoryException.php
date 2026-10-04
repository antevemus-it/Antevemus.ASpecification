<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories\Exceptions;

use RuntimeException;
use Throwable;

/**
 * RepositoryException - Exceção base para falhas em repositórios
 *
 * Exceção base lançada por repositórios do ecossistema Specification quando ocorre
 * uma falha interna na execução de operações de armazenamento, consulta ou particionamento.
 *
 * Funcionalidades:
 * - Encapsulamento de falhas internas com causa original (Throwable)
 * - Identificação explícita da operação que falhou
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RepositoryException extends RuntimeException
{
    /**
     * Construtor da exceção de repositório.
     *
     * @param string $message Mensagem descritiva da falha
     * @param int $code Código numérico de erro
     * @param Throwable|null $previous Exceção anterior que causou a falha
     */
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
