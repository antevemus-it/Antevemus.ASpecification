<?php

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\IStringSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractStringSpecificationFactory class.
 *
 * Classe abstrata base para fábricas de especificações de strings.
 *
 * Fornece implementações padrão para métodos alias e métodos auxiliares
 * de validação para implementações concretas.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractStringSpecificationFactory implements IStringSpecificationFactory
{
    /**
     * Valida um padrão de expressão regular.
     *
     * Método auxiliar para implementações concretas validarem padrões regex
     * antes de criar especificações.
     *
     * @param string $pattern Padrão regex a validar
     * @throws \InvalidArgumentException Se o padrão for inválido
     */
    protected function validateRegexPattern(string $pattern): void
    {
        if (empty($pattern)) {
            throw new \InvalidArgumentException('Regex pattern cannot be empty');
        }

        // Tentar compilar o padrão para validar
        $result = @preg_match($pattern, '');
        if ($result === false) {
            throw new \InvalidArgumentException("Invalid regex pattern: {$pattern}");
        }
    }

    /**
     * Valida uma string de valor.
     *
     * Método auxiliar para implementações concretas validarem strings
     * antes de criar especificações.
     *
     * @param string $value String a validar
     * @param bool $allowEmpty Se strings vazias são permitidas (padrão: false)
     * @throws \InvalidArgumentException Se a string for inválida
     */
    protected function validateString(string $value, bool $allowEmpty = false): void
    {
        if (!$allowEmpty && empty($value)) {
            throw new \InvalidArgumentException('String value cannot be empty');
        }
    }

    /**
     * {@inheritdoc}
     */
    abstract public function isBlank(): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function blank(): ISpecification
    {
        return $this->isBlank();
    }

    /**
     * {@inheritdoc}
     */
    abstract public function equalIgnoringCase(string $value): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function equalsIgnoringCase(string $value): ISpecification
    {
        return $this->equalIgnoringCase($value);
    }

    /**
     * {@inheritdoc}
     */
    public function isEqualIgnoringCase(string $value): ISpecification
    {
        return $this->equalIgnoringCase($value);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function matchesRegex(string $pattern): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function matchesRegularExpression(string $pattern): ISpecification
    {
        return $this->matchesRegex($pattern);
    }

    /**
     * {@inheritdoc}
     */
    public function matches(string $pattern): ISpecification
    {
        return $this->matchesRegex($pattern);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function matchesWildcard(string $wildcardExpression): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function like(string $wildcardExpression): ISpecification
    {
        return $this->matchesWildcard($wildcardExpression);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function matchesWildcardIgnoringCase(string $wildcardExpression): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function contains(string $substring, bool $caseSensitive = true): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function startsWith(string $prefix, bool $caseSensitive = true): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function endsWith(string $suffix, bool $caseSensitive = true): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function hasLength(ISpecification $lengthSpecification): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isValidDate(?string $format = null): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function isDate(?string $format = null): ISpecification
    {
        return $this->isValidDate($format);
    }

    /**
     * {@inheritdoc}
     */
    public function enumCase(string $enumClass): ISpecification
    {
        return new \Antevemus\ASpecification\Specifications\String\EnumNameStringSpecification($enumClass);
    }
}
