<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DSL;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Spec;
use DateTimeInterface;

/**
 * functions.php - Funções globais da DSL do Antevemus.ASpecification
 *
 * Fornece funções puras e estáticas no namespace Antevemus\ASpecification\DSL
 * para escrita de especificações e regras de negócio em linguagem natural
 * quase-falada através do construto "use function".
 *
 * Funcionalidades:
 * - Início de especificação parametrizada (specify)
 * - Composição lógica de predicados (allOf, anyOf, not)
 * - Avaliação de identidade e comparação (is, equalTo, equal, notEqual, greaterThan, lessThan, in)
 * - Avaliação temporal e de datas (before, isBefore, after, isAfter, at, between)
 * - Avaliação de predicados especiais e cadeias de texto (matches, contains, startsWith, endsWith)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage DSL
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

/**
 * Inicia a construção fluente de uma especificação composta tipada para uma classe ou tipo alvo.
 *
 * Permite encadear cláusulas `where()`, `and()`, `or()` e `not()` com legibilidade natural.
 *
 * @param class-string|string $type Nome da classe, interface ou tipo primitivo alvo da especificação.
 * @return ICompositeSpecification Especificação composta fluente inicializada.
 */
function specify(string $type): ICompositeSpecification
{
    return Spec::specify($type);
}

/**
 * Cria uma especificação vinculada a uma propriedade de objeto ou chave de array.
 *
 * Suporta acesso a propriedades públicas, getters (getProp, prop),
 * métodos booleanos (isProp, hasProp), ArrayAccess e dot-notation ('user.address.city').
 *
 * Exemplo de uso:
 * <code>
 * $isEligible = prop('age', greaterThanOrEqualTo(18))
 *     ->and(prop('status', equal('ACTIVE')))
 *     ->and(prop('address.city', equal('São Paulo')));
 * </code>
 *
 * @param string $propertyName Nome da propriedade ou caminho pontilhado.
 * @param ISpecification $specification Regra a ser aplicada sobre o valor da propriedade.
 * @param ISpecification|null $baseSpecification Especificação do tipo base (default: AlwaysTrue).
 * @return PropertySpecification
 */
function prop(
    string $propertyName,
    ISpecification $specification,
    ?ISpecification $baseSpecification = null
): PropertySpecification {
    return Spec::property($propertyName, $specification, $baseSpecification);
}

/**
 * Alias de prop().
 *
 * @param string $propertyName Nome da propriedade ou caminho pontilhado.
 * @param ISpecification $specification Regra a ser aplicada sobre o valor da propriedade.
 * @param ISpecification|null $baseSpecification Especificação do tipo base (default: AlwaysTrue).
 * @return PropertySpecification
 */
function property(
    string $propertyName,
    ISpecification $specification,
    ?ISpecification $baseSpecification = null
): PropertySpecification {
    return Spec::property($propertyName, $specification, $baseSpecification);
}

/**
 * Cria uma especificação de conjunção lógica (AND) que exige que TODAS as especificações fornecidas sejam satisfeitas.
 *
 * Se nenhuma especificação for repassada, retorna AlwaysTrueSpecification.
 *
 * @param ISpecification ...$specifications Lista variável de especificações a serem combinadas por AND.
 * @return ISpecification Especificação composta contendo a conjunção de todas as regras.
 */
function allOf(ISpecification ...$specifications): ISpecification
{
    return Spec::allOf(...$specifications);
}

/**
 * Cria uma especificação de disjunção lógica (OR) que exige que PELO MENOS UMA das especificações fornecidas seja satisfeita.
 *
 * Se nenhuma especificação for repassada, retorna AlwaysFalseSpecification.
 *
 * @param ISpecification ...$specifications Lista variável de especificações a serem combinadas por OR.
 * @return ISpecification Especificação composta contendo a disjunção de todas as regras.
 */
function anyOf(ISpecification ...$specifications): ISpecification
{
    return Spec::anyOf(...$specifications);
}

/**
 * Cria uma especificação de negação lógica (NOT) que inverte o resultado da especificação fornecida.
 *
 * @param ISpecification $specification A especificação cuja condição deve ser negada.
 * @return ISpecification Especificação negada.
 */
function not(ISpecification $specification): ISpecification
{
    return Spec::not($specification);
}

/**
 * Cria uma especificação de igualdade estrita ou identidade para o valor repassado.
 *
 * @param mixed $value Valor esperado para validação de igualdade.
 * @return ISpecification Especificação folha de igualdade.
 */
function is(mixed $value): ISpecification
{
    return Spec::is($value);
}

/**
 * Cria uma especificação folha de igualdade estrita (`===`).
 *
 * @param mixed $value Valor esperado.
 * @return ISpecification Especificação de igualdade.
 */
function equalTo(mixed $value): ISpecification
{
    return Spec::equalTo($value);
}

/**
 * Cria uma especificação folha de igualdade estrita (`===`). Alias sintático para `equalTo()`.
 *
 * @param mixed $value Valor esperado.
 * @return ISpecification Especificação de igualdade.
 */
function equal(mixed $value): ISpecification
{
    return Spec::equal($value);
}

/**
 * Cria uma especificação folha de desigualdade estrita (`!==`).
 *
 * @param mixed $value Valor que o candidato NÃO deve possuir.
 * @return ISpecification Especificação de desigualdade.
 */
function notEqual(mixed $value): ISpecification
{
    return Spec::notEqual($value);
}

/**
 * Cria uma especificação de comparação maior que (`>`).
 *
 * @param mixed $value Limite inferior exclusivo.
 * @return ISpecification Especificação de comparação maior que.
 */
function greaterThan(mixed $value): ISpecification
{
    return Spec::greaterThan($value);
}

/**
 * Cria uma especificação de comparação maior ou igual a (`>=`).
 *
 * @param mixed $value Limite inferior inclusivo.
 * @return ISpecification Especificação de comparação maior ou igual.
 */
function greaterThanOrEqualTo(mixed $value): ISpecification
{
    return Spec::greaterThanOrEqualTo($value);
}

/**
 * Cria uma especificação de comparação menor que (`<`).
 *
 * @param mixed $value Limite superior exclusivo.
 * @return ISpecification Especificação de comparação menor que.
 */
function lessThan(mixed $value): ISpecification
{
    return Spec::lessThan($value);
}

/**
 * Cria uma especificação de comparação menor ou igual a (`<=`).
 *
 * @param mixed $value Limite superior inclusivo.
 * @return ISpecification Especificação de comparação menor ou igual.
 */
function lessThanOrEqualTo(mixed $value): ISpecification
{
    return Spec::lessThanOrEqualTo($value);
}

/**
 * Cria uma especificação de pertinência a um conjunto de valores (equivalente ao operador IN / disjunção de igualdades).
 *
 * @param mixed ...$values Valores aceitos pelo conjunto.
 * @return ISpecification Especificação disjuntiva de pertencimento.
 */
function in(mixed ...$values): ISpecification
{
    return Spec::in(...$values);
}

/**
 * Cria uma especificação temporal ou de ordenação anterior a um dado valor.
 *
 * @param mixed $value Valor ou data de referência limite superior.
 * @return ISpecification Especificação de anterioridade.
 */
function before(mixed $value): ISpecification
{
    return Spec::before($value);
}

/**
 * Cria uma especificação temporal anterior a um valor ou data. Alias sintático para `before()`.
 *
 * @param mixed $value Valor ou data de referência limite superior.
 * @return ISpecification Especificação de anterioridade.
 */
function isBefore(mixed $value): ISpecification
{
    return Spec::isBefore($value);
}

/**
 * Cria uma especificação temporal ou de ordenação posterior a um dado valor.
 *
 * @param mixed $value Valor ou data de referência limite inferior.
 * @return ISpecification Especificação de posterioridade.
 */
function after(mixed $value): ISpecification
{
    return Spec::after($value);
}

/**
 * Cria uma especificação temporal posterior a um valor ou data. Alias sintático para `after()`.
 *
 * @param mixed $value Valor ou data de referência limite inferior.
 * @return ISpecification Especificação de posterioridade.
 */
function isAfter(mixed $value): ISpecification
{
    return Spec::isAfter($value);
}

/**
 * Cria uma especificação temporal que exige exatidão cronológica com a data e hora fornecidas.
 *
 * @param DateTimeInterface $date Data e hora exatas de comparação.
 * @return ISpecification Especificação temporal de igualdade de instante.
 */
function at(DateTimeInterface $date): ISpecification
{
    return Spec::at($date);
}

/**
 * Cria uma especificação temporal que exige exatidão cronológica com a data fornecida. Alias para `at()`.
 *
 * @param DateTimeInterface $date Data e hora de comparação.
 * @return ISpecification Especificação temporal de igualdade cronológica.
 */
function atTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::atTheSameTimeAs($date);
}

/**
 * Cria uma especificação temporal anterior ou coincidente com a data limite informada (menor ou igual a).
 *
 * @param DateTimeInterface $date Data limite superior inclusiva.
 * @return ISpecification Especificação temporal menor ou igual.
 */
function beforeOrAt(DateTimeInterface $date): ISpecification
{
    return Spec::beforeOrAt($date);
}

/**
 * Cria uma especificação temporal anterior ou coincidente com a data fornecida. Alias para `beforeOrAt()`.
 *
 * @param DateTimeInterface $date Data limite superior inclusiva.
 * @return ISpecification Especificação temporal menor ou igual.
 */
function beforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::beforeOrAtTheSameTimeAs($date);
}

/**
 * Cria uma especificação temporal anterior ou coincidente com a data fornecida. Alias sintático fluente.
 *
 * @param DateTimeInterface $date Data limite superior inclusiva.
 * @return ISpecification Especificação temporal menor ou igual.
 */
function isBeforeOrAt(DateTimeInterface $date): ISpecification
{
    return Spec::isBeforeOrAt($date);
}

/**
 * Cria uma especificação temporal anterior ou coincidente com a data fornecida. Alias sintático fluente longo.
 *
 * @param DateTimeInterface $date Data limite superior inclusiva.
 * @return ISpecification Especificação temporal menor ou igual.
 */
function isBeforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::isBeforeOrAtTheSameTimeAs($date);
}

/**
 * Cria uma especificação temporal posterior ou coincidente com a data limite informada (maior ou igual a).
 *
 * @param DateTimeInterface $date Data limite inferior inclusiva.
 * @return ISpecification Especificação temporal maior ou igual.
 */
function afterOrAt(DateTimeInterface $date): ISpecification
{
    return Spec::afterOrAt($date);
}

/**
 * Cria uma especificação temporal posterior ou coincidente com a data fornecida. Alias para `afterOrAt()`.
 *
 * @param DateTimeInterface $date Data limite inferior inclusiva.
 * @return ISpecification Especificação temporal maior ou igual.
 */
function afterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::afterOrAtTheSameTimeAs($date);
}

/**
 * Cria uma especificação temporal posterior ou coincidente com a data fornecida. Alias sintático fluente.
 *
 * @param DateTimeInterface $date Data limite inferior inclusiva.
 * @return ISpecification Especificação temporal maior ou igual.
 */
function isAfterOrAt(DateTimeInterface $date): ISpecification
{
    return Spec::isAfterOrAt($date);
}

/**
 * Cria uma especificação temporal posterior ou coincidente com a data fornecida. Alias sintático fluente longo.
 *
 * @param DateTimeInterface $date Data limite inferior inclusiva.
 * @return ISpecification Especificação temporal maior ou igual.
 */
function isAfterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
{
    return Spec::isAfterOrAtTheSameTimeAs($date);
}

/**
 * Cria uma especificação temporal de intervalo que valida se uma data está contida entre o início e o fim (inclusivo).
 *
 * @param DateTimeInterface $start Data inicial do intervalo.
 * @param DateTimeInterface $end Data final do intervalo.
 * @return ISpecification Especificação de pertencimento ao intervalo fechado.
 */
function between(DateTimeInterface $start, DateTimeInterface $end): ISpecification
{
    return Spec::between($start, $end);
}

/**
 * Retorna uma especificação tautológica universal que é sempre satisfeita por qualquer candidato.
 *
 * @return ISpecification Instância da AlwaysTrueSpecification.
 */
function alwaysTrue(): ISpecification
{
    return Spec::alwaysTrue();
}

/**
 * Retorna uma especificação contraditória universal que nunca é satisfeita por nenhum candidato.
 *
 * @return ISpecification Instância da AlwaysFalseSpecification.
 */
function alwaysFalse(): ISpecification
{
    return Spec::alwaysFalse();
}

/**
 * Cria uma especificação que valida se o candidato ou propriedade é estritamente nulo (`=== null`).
 *
 * @return ISpecification Especificação de nulidade.
 */
function isNull(): ISpecification
{
    return Spec::isNull();
}

/**
 * Cria uma especificação que valida se o candidato ou propriedade é não-nulo (`!== null`).
 *
 * @return ISpecification Especificação de não-nulidade.
 */
function isNotNull(): ISpecification
{
    return Spec::isNotNull();
}

/**
 * Cria uma especificação que valida se o candidato ou propriedade avalia como booleano estritamente verdadeiro (`=== true`).
 *
 * @return ISpecification Especificação de verdade booleana.
 */
function isTrue(): ISpecification
{
    return Spec::isTrue();
}

/**
 * Cria uma especificação que valida se o candidato ou propriedade avalia como booleano estritamente falso (`=== false`).
 *
 * @return ISpecification Especificação de falsidade booleana.
 */
function isFalse(): ISpecification
{
    return Spec::isFalse();
}

/**
 * Cria uma especificação que valida se uma string é vazia ou composta apenas por caracteres de espaço em branco.
 *
 * @return ISpecification Especificação de string em branco.
 */
function isBlank(): ISpecification
{
    return Spec::isBlank();
}

/**
 * Cria uma especificação que valida uma string contra uma expressão regular PCRE (`preg_match`).
 *
 * @param string $pattern Padrão de expressão regular (ex.: '/^[0-9]{3}\.[0-9]{3}\.[0-9]{3}-[0-9]{2}$/').
 * @return ISpecification Especificação de correspondência por regex.
 */
function matches(string $pattern): ISpecification
{
    return Spec::matches($pattern);
}

/**
 * Cria uma especificação que valida se uma string contém a substring informada.
 *
 * @param string $substring Texto a ser localizado.
 * @param bool $caseSensitive Define se a busca diferencia maiúsculas de minúsculas (padrão: true).
 * @return ISpecification Especificação de contenção de substring.
 */
function contains(string $substring, bool $caseSensitive = true): ISpecification
{
    return Spec::contains($substring, $caseSensitive);
}

/**
 * Cria uma especificação que valida se uma string inicia com o prefixo informado.
 *
 * @param string $prefix Prefixo esperado.
 * @param bool $caseSensitive Define se a verificação diferencia maiúsculas de minúsculas (padrão: true).
 * @return ISpecification Especificação de início de string.
 */
function startsWith(string $prefix, bool $caseSensitive = true): ISpecification
{
    return Spec::startsWith($prefix, $caseSensitive);
}

/**
 * Cria uma especificação que valida se uma string termina com o sufixo informado.
 *
 * @param string $suffix Sufixo esperado.
 * @param bool $caseSensitive Define se a verificação diferencia maiúsculas de minúsculas (padrão: true).
 * @return ISpecification Especificação de término de string.
 */
function endsWith(string $suffix, bool $caseSensitive = true): ISpecification
{
    return Spec::endsWith($suffix, $caseSensitive);
}

/**
 * Cria uma especificação que valida se uma coleção, array ou string está vazia (contagem/comprimento igual a 0).
 *
 * @return ISpecification Especificação de vacuidade.
 */
function isEmpty(): ISpecification
{
    return Spec::isEmpty();
}

/**
 * Cria uma especificação de tamanho que valida o número de elementos de uma coleção ou array contra uma especificação de tamanho.
 *
 * @param ISpecification $sizeSpecification Especificação aplicada sobre a contagem inteira de elementos (ex: equalTo(5)).
 * @return ISpecification Especificação de cardinalidade/tamanho de coleção.
 */
function hasSize(ISpecification $sizeSpecification): ISpecification
{
    return Spec::hasSize($sizeSpecification);
}

/**
 * Cria uma especificação de comprimento que valida o número de caracteres de uma string contra uma especificação de comprimento.
 *
 * @param ISpecification $lengthSpecification Especificação aplicada sobre a quantidade de caracteres (ex: greaterThan(10)).
 * @return ISpecification Especificação de comprimento de string.
 */
function hasLength(ISpecification $lengthSpecification): ISpecification
{
    return Spec::hasLength($lengthSpecification);
}
