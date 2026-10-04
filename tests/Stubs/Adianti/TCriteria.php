<?php

declare(strict_types=1);

namespace Adianti\Database;

/**
 * TCriteria - Stub de compatibilidade do Adianti Framework para testes
 *
 * @version    8.6
 * @package    database
 * @author     Pablo Dall'Oglio
 * @license    https://adiantiframework.com.br/license
 */
class TCriteria extends TExpression
{
    private array $expressions = [];
    private array $operators = [];
    private array $properties = [];
    private bool $caseInsensitive = false;

    public function __construct()
    {
        $this->properties['order']     = '';
        $this->properties['offset']    = 0;
        $this->properties['direction'] = '';
        $this->properties['group']     = '';
        $this->properties['limit']     = null;
    }

    public static function create(?array $simple_filters = null, ?array $properties = null): self
    {
        $criteria = new self();
        if ($simple_filters) {
            foreach ($simple_filters as $left => $right) {
                $criteria->add(new TFilter($left, '=', $right));
            }
        }

        if ($properties) {
            foreach ($properties as $property => $value) {
                if (!empty($value)) {
                    $criteria->setProperty($property, $value);
                }
            }
        }

        return $criteria;
    }

    public function add(TExpression $expression, ?string $operator = TExpression::AND_OPERATOR): void
    {
        if (empty($this->expressions)) {
            $operator = null;
        }

        $this->expressions[] = $expression;
        $this->operators[]   = $operator;
    }

    public function getExpressions(): array
    {
        return $this->expressions;
    }

    public function getOperators(): array
    {
        return $this->operators;
    }

    public function isEmpty(): bool
    {
        return count($this->expressions) === 0;
    }

    public function setProperty(string $property, mixed $value): void
    {
        $this->properties[$property] = $value;
    }

    public function getProperty(string $property): mixed
    {
        return $this->properties[$property] ?? null;
    }

    public function resetProperties(): void
    {
        $this->properties['limit']     = null;
        $this->properties['order']     = null;
        $this->properties['offset']    = null;
        $this->properties['group']     = null;
        $this->properties['direction'] = null;
    }

    public function dump($prepared = false): string
    {
        if (empty($this->expressions)) {
            return '';
        }

        $result = '';
        foreach ($this->expressions as $i => $expression) {
            $operator = $this->operators[$i];
            if ($this->caseInsensitive && method_exists($expression, 'setCaseInsensitive')) {
                $expression->setCaseInsensitive(true);
            }
            $result .= $operator . $expression->dump($prepared) . ' ';
        }

        $result = trim($result);
        return $result !== '' ? "({$result})" : '';
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
