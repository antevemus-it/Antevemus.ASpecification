<?php

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IStringSpecificationFactory interface.
 *
 * Contrato para fábricas que criam especificações para strings.
 *
 * Esta interface fornece métodos para criar especificações especializadas
 * em operações com strings, incluindo comparações case-insensitive,
 * expressões regulares, wildcards e validações de formato.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IStringSpecificationFactory extends ISpecificationFactory
{
    /**
     * Cria especificação que verifica string vazia ou em branco.
     *
     * Verifica se a string é null, vazia ("") ou contém apenas espaços em branco.
     *
     * @return ISpecification<string> Especificação de string em branco
     */
    public function isBlank(): ISpecification;

    /**
     * Alias para isBlank().
     *
     * @return ISpecification<string>
     */
    public function blank(): ISpecification;

    /**
     * Cria especificação que verifica igualdade ignorando case.
     *
     * Compara strings de forma case-insensitive.
     *
     * Exemplo:
     * <code>
     * $spec = $factory->equalIgnoringCase('HELLO');
     * $spec->isSatisfiedBy('hello'); // true
     * $spec->isSatisfiedBy('Hello'); // true
     * $spec->isSatisfiedBy('hi');    // false
     * </code>
     *
     * @param string $value String para comparação
     * @return ISpecification<string> Especificação de igualdade case-insensitive
     */
    public function equalIgnoringCase(string $value): ISpecification;

    /**
     * Alias para equalIgnoringCase().
     *
     * @param string $value String para comparação
     * @return ISpecification<string>
     */
    public function equalsIgnoringCase(string $value): ISpecification;

    /**
     * Alias para equalIgnoringCase().
     *
     * @param string $value String para comparação
     * @return ISpecification<string>
     */
    public function isEqualIgnoringCase(string $value): ISpecification;

    /**
     * Cria especificação que verifica match com expressão regular.
     *
     * Exemplo:
     * <code>
     * $spec = $factory->matchesRegex('/^[A-Z]{3}-\d{3}$/');
     * $spec->isSatisfiedBy('ABC-123'); // true
     * $spec->isSatisfiedBy('abc-123'); // false
     * </code>
     *
     * @param string $pattern Padrão de expressão regular (PCRE)
     * @return ISpecification<string> Especificação de match regex
     * @throws \InvalidArgumentException Se o padrão regex for inválido
     */
    public function matchesRegex(string $pattern): ISpecification;

    /**
     * Alias para matchesRegex().
     *
     * @param string $pattern Padrão de expressão regular (PCRE)
     * @return ISpecification<string>
     */
    public function matchesRegularExpression(string $pattern): ISpecification;

    /**
     * Alias para matchesRegex().
     *
     * @param string $pattern Padrão de expressão regular (PCRE)
     * @return ISpecification<string>
     */
    public function matches(string $pattern): ISpecification;

    /**
     * Cria especificação que verifica match com expressão wildcard.
     *
     * Suporta wildcards: * (qualquer sequência) e ? (qualquer caractere).
     * Case-sensitive por padrão.
     *
     * Exemplo:
     * <code>
     * $spec = $factory->matchesWildcard('user_*');
     * $spec->isSatisfiedBy('user_123'); // true
     * $spec->isSatisfiedBy('user_abc'); // true
     * $spec->isSatisfiedBy('admin_123'); // false
     * </code>
     *
     * @param string $wildcardExpression Expressão com wildcards (* e ?)
     * @return ISpecification<string> Especificação de match wildcard
     */
    public function matchesWildcard(string $wildcardExpression): ISpecification;

    /**
     * Alias para matchesWildcard().
     *
     * Uso idiomático SQL: "like 'user_%'"
     *
     * @param string $wildcardExpression Expressão com wildcards (* e ?)
     * @return ISpecification<string>
     */
    public function like(string $wildcardExpression): ISpecification;

    /**
     * Cria especificação que verifica match com wildcard ignorando case.
     *
     * Similar a matchesWildcard() mas case-insensitive.
     *
     * @param string $wildcardExpression Expressão com wildcards (* e ?)
     * @return ISpecification<string> Especificação de match wildcard case-insensitive
     */
    public function matchesWildcardIgnoringCase(string $wildcardExpression): ISpecification;

    /**
     * Cria especificação que verifica se string contém substring.
     *
     * @param string $substring Substring a procurar
     * @param bool $caseSensitive Se a busca deve ser case-sensitive (padrão: true)
     * @return ISpecification<string> Especificação de contenção de substring
     */
    public function contains(string $substring, bool $caseSensitive = true): ISpecification;

    /**
     * Cria especificação que verifica se string começa com prefixo.
     *
     * @param string $prefix Prefixo esperado
     * @param bool $caseSensitive Se a verificação deve ser case-sensitive (padrão: true)
     * @return ISpecification<string> Especificação de início com prefixo
     */
    public function startsWith(string $prefix, bool $caseSensitive = true): ISpecification;

    /**
     * Cria especificação que verifica se string termina com sufixo.
     *
     * @param string $suffix Sufixo esperado
     * @param bool $caseSensitive Se a verificação deve ser case-sensitive (padrão: true)
     * @return ISpecification<string> Especificação de término com sufixo
     */
    public function endsWith(string $suffix, bool $caseSensitive = true): ISpecification;

    /**
     * Cria especificação que verifica comprimento da string.
     *
     * @param ISpecification<int> $lengthSpecification Especificação para o comprimento
     * @return ISpecification<string> Especificação de comprimento de string
     */
    public function hasLength(ISpecification $lengthSpecification): ISpecification;

    /**
     * Cria especificação que verifica se string é uma data válida.
     *
     * Tenta fazer parse da string como data usando formatos comuns do PHP.
     * Suporta formatos: Y-m-d, d/m/Y, d-m-Y, d.m.Y, Ymd, etc.
     *
     * Exemplo:
     * <code>
     * $spec = $factory->isValidDate();
     * $spec->isSatisfiedBy('2025-01-15'); // true
     * $spec->isSatisfiedBy('15/01/2025'); // true
     * $spec->isSatisfiedBy('invalid');    // false
     * </code>
     *
     * @param string|null $format Formato específico de data (opcional). Se null, tenta formatos comuns
     * @return ISpecification<string> Especificação de validação de data
     */
    public function isValidDate(?string $format = null): ISpecification;

    /**
     * Alias para isValidDate().
     *
     * @param string|null $format Formato específico de data (opcional)
     * @return ISpecification<string>
     */
     public function isDate(?string $format = null): ISpecification;

    /**
     * Cria especificação que valida se a string corresponde a um case de um Enum PHP.
     *
     * @param class-string $enumClass A classe do Enum a ser validada.
     * @return ISpecification<string>
     */
    public function enumCase(string $enumClass): ISpecification;
}
