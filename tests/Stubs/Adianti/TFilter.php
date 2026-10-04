<?php

declare(strict_types=1);

namespace Adianti\Database;

/**
 * TFilter - Stub de compatibilidade do Adianti Framework para testes
 *
 * @version    8.6
 * @package    database
 * @author     Pablo Dall'Oglio
 * @license    https://adiantiframework.com.br/license
 */
class TFilter extends TExpression
{
    private mixed $variable;
    private mixed $operator;
    private mixed $value;
    private mixed $value2;
    private array $preparedVars = [];
    private bool $caseInsensitive = false;
    private static int $paramCounter = 0;

    public function __construct(mixed $variable, mixed $operator, mixed $value, mixed $value2 = null)
    {
        $this->variable = $variable;
        $this->operator = $operator;
        $this->value    = $value;
        $this->value2   = $value2;
    }

    public function getVariable(): mixed
    {
        return $this->variable;
    }

    public function getOperator(): mixed
    {
        return $this->operator;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getValue2(): mixed
    {
        return $this->value2;
    }

    private function transform(mixed $value, bool $prepared = false): mixed
    {
        if (is_array($value)) {
            $foo = [];
            foreach ($value as $x) {
                if (is_numeric($x)) {
                    if ($prepared) {
                        $p = ':par_' . (++self::$paramCounter);
                        $this->preparedVars[$p] = $x;
                        $foo[] = $p;
                    } else {
                        $foo[] = $x;
                    }
                } elseif (is_string($x)) {
                    if ($prepared) {
                        $p = ':par_' . (++self::$paramCounter);
                        $this->preparedVars[$p] = $x;
                        $foo[] = $p;
                    } else {
                        $foo[] = "'{$x}'";
                    }
                } elseif (is_bool($x)) {
                    $foo[] = $x ? 'TRUE' : 'FALSE';
                }
            }
            return '(' . implode(',', $foo) . ')';
        }

        if (is_string($value)) {
            if ($prepared) {
                $p = ':par_' . (++self::$paramCounter);
                $this->preparedVars[$p] = $value;
                return $p;
            }
            return "'{$value}'";
        }

        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if ($prepared) {
            $p = ':par_' . (++self::$paramCounter);
            $this->preparedVars[$p] = $value;
            return $p;
        }

        return $value;
    }

    public function dump($prepared = false): string
    {
        $this->preparedVars = [];
        $val1 = $this->transform($this->value, $prepared);

        if ($this->value2 !== null) {
            $val2 = $this->transform($this->value2, $prepared);
            return "{$this->variable} {$this->operator} {$val1} AND {$val2}";
        }

        $var = $this->variable;
        $op  = $this->operator;

        if ($this->caseInsensitive && stripos((string)$op, 'like') !== false) {
            $var = "UPPER({$var})";
            $val1 = "UPPER({$val1})";
            $op  = str_ireplace('ilike', 'LIKE', (string)$op);
        }

        return "{$var} {$op} {$val1}";
    }

    public function getPreparedVars(): array
    {
        return $this->preparedVars;
    }

    public function setCaseInsensitive(bool $value): void
    {
        $this->caseInsensitive = $value;
    }

    public function getCaseInsensitive(): bool
    {
        return $this->caseInsensitive;
    }
}
