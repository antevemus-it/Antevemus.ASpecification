<?php

declare(strict_types=1);

namespace Adianti\Database;

/**
 * TFilter - Stub de compatibilidade do Adianti Framework para testes
 *
 * @version    8.6
 * (pseudo-variables of a subselect since the suite of ASpecification 2.5.0)
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

        // Espelho fiel do TFilter real (lib/adianti/database/TFilter.php:104-121): estes três
        // formatos são emitidos como SQL CRU, mesmo em modo prepared. Mantidos aqui para que a
        // suíte enxergue o risco que o CriteriaSpecificationVisitor bloqueia (BUG-20261007-KJ36).
        if (substr(strtoupper((string) $value), 0, 7) == '(SELECT') {
            // Forward 048, RN-04 (2.5.0): as the real one (TFilter.php:105-109 and 176-192, identical in 8.1,
            // 8.6 and 8.7), the pseudo-variables `:[v]:` of a subselect become `:par_N` bound EVEN in plain
            // mode, after `#`, `--` and `/*` were removed from the whole text
            return $this->replacePseudoPreparedVars(str_replace(['#', '--', '/*'], ['', '', ''], (string) $value));
        }
        if (strpos((string) $value, '{session.') !== false) {
            return (string) $value; // o real substitui {session.x} pelo valor da sessão, ainda cru
        }
        if (substr((string) $value, 0, 6) == 'NOESC:') {
            return substr(str_replace(['#', '--', '/*'], ['', '', ''], (string) $value), 6);
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

    /**
     * Espelho de TFilter::replacePseudoPreparedVars() do Adianti real (8.1 = 8.6 = 8.7): cada `:[v]:` vira um
     * `:par_N` ligado ao texto entre os marcadores, também fora do modo prepared.
     */
    private function replacePseudoPreparedVars(string $value): string
    {
        $result = $value;
        preg_match_all('#:\[(.*?)\]:#', $value, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $match) {
                $p = ':par_' . (++self::$paramCounter);
                $result = str_replace(":[{$match}]:", $p, $result);
                $this->preparedVars[$p] = $match;
            }
        }

        return $result;
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
