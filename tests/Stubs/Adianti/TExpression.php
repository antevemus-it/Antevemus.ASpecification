<?php

declare(strict_types=1);

namespace Adianti\Database;

/**
 * TExpression - Stub de compatibilidade do Adianti Framework para testes
 *
 * @version    8.6
 * @package    database
 * @author     Pablo Dall'Oglio
 * @license    https://adiantiframework.com.br/license
 */
abstract class TExpression
{
    public const AND_OPERATOR = 'AND ';
    public const OR_OPERATOR  = 'OR ';

    abstract public function dump($prepared = false);
}
