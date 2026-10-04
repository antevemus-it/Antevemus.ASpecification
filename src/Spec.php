<?php

declare(strict_types=1);

namespace Antevemus\ASpecification;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Factory\SpecificationFactory;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use DateTimeInterface;

/**
 * Spec - Facade estática unificada para o padrão Specification
 *
 * Provê atalhos estáticos e ergonomia em linguagem natural para criação, composição
 * e avaliação de especificações na biblioteca Antevemus.ASpecification.
 *
 * Funcionalidades:
 * - Atalhos tipados para composição lógica (allOf, anyOf, not)
 * - Criação de especificações parametrizadas de tipo (specify)
 * - Atalhos para comparações de valor (is, equalTo, equal, greaterThan, lessThan, in)
 * - Atalhos para comparações temporais (before, isBefore, after, isAfter, between)
 * - Atalhos para verificações de texto e coleções (contains, startsWith, isEmpty)
 * - Redirecionamento dinâmico via __callStatic para toda a SpecificationFactory
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Facade
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class Spec
{
    private static ?SpecificationFactory $factory = null;

    /**
     * Construtor privado para impedir instanciação direta da Facade.
     */
    private function __construct()
    {
    }

    /**
     * Obtém a instância compartilhada da SpecificationFactory.
     *
     * @return SpecificationFactory
     */
    public static function getFactory(): SpecificationFactory
    {
        if (self::$factory === null) {
            self::$factory = new SpecificationFactory();
        }
        return self::$factory;
    }

    /**
     * Configura ou substitui a instância interna da SpecificationFactory (útil para testes com mock).
     *
     * @param SpecificationFactory|null $factory
     * @return void
     */
    public static function setFactory(?SpecificationFactory $factory): void
    {
        self::$factory = $factory;
    }

    // ==========================================
    // 1. Tipo e Especificações Parametrizadas
    // ==========================================

    /**
     * Inicia a construção de uma especificação parametrizada vinculada a uma classe de domínio.
     *
     * @param string $type Nome completo da classe ou interface (ex.: Customer::class)
     * @return ICompositeSpecification
     */
    public static function specify(string $type): ICompositeSpecification
    {
        return self::getFactory()->specify($type);
    }

    /**
     * Cria uma especificação vinculada a uma propriedade ou atributo de objeto/entidade.
     *
     * @param string $propertyName Nome da propriedade
     * @param ISpecification $specification Regra a ser aplicada sobre o valor da propriedade
     * @param ISpecification|null $baseSpecification Especificação do tipo base (default: AlwaysTrue)
     * @return PropertySpecification
     */
    public static function property(
        string $propertyName,
        ISpecification $specification,
        ?ISpecification $baseSpecification = null
    ): PropertySpecification {
        return new PropertySpecification(
            $baseSpecification ?? self::alwaysTrue(),
            $propertyName,
            $specification
        );
    }

    // ==========================================
    // 2. Operadores de Composição Lógica
    // ==========================================

    /**
     * Cria uma conjunção lógica (AND) contendo todas as especificações fornecidas.
     *
     * @param ISpecification ...$specifications
     * @return ISpecification
     */
    public static function allOf(ISpecification ...$specifications): ISpecification
    {
        return self::getFactory()->allOf(...$specifications);
    }

    /**
     * Cria uma disjunção lógica (OR) contendo qualquer uma das especificações fornecidas.
     *
     * @param ISpecification ...$specifications
     * @return ISpecification
     */
    public static function anyOf(ISpecification ...$specifications): ISpecification
    {
        return self::getFactory()->anyOf(...$specifications);
    }

    /**
     * Inverte a especificação fornecida através de negação lógica (NOT).
     *
     * @param ISpecification $specification
     * @return ISpecification
     */
    public static function not(ISpecification $specification): ISpecification
    {
        return self::getFactory()->not($specification);
    }

    // ==========================================
    // 3. Comparação de Valores e Identidade
    // ==========================================

    /**
     * Cria uma especificação de igualdade de valor ou de envolvimento sintático (wrapper).
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function is(mixed $value): ISpecification
    {
        return self::getFactory()->is($value);
    }

    /**
     * Especifica que o valor deve ser igual ao valor esperado.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function equalTo(mixed $value): ISpecification
    {
        return self::getFactory()->equalTo($value);
    }

    /**
     * Alias de equalTo.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function equal(mixed $value): ISpecification
    {
        return self::getFactory()->equalTo($value);
    }

    /**
     * Especifica que o valor deve ser diferente do valor esperado.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function notEqual(mixed $value): ISpecification
    {
        return self::getFactory()->notEqual($value);
    }

    /**
     * Especifica que o valor deve ser estritamente maior que o limite.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function greaterThan(mixed $value): ISpecification
    {
        return self::getFactory()->greaterThan($value);
    }

    /**
     * Especifica que o valor deve ser maior ou igual ao limite.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function greaterThanOrEqualTo(mixed $value): ISpecification
    {
        return self::getFactory()->greaterThanOrEqualTo($value);
    }

    /**
     * Especifica que o valor deve ser estritamente menor que o limite.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function lessThan(mixed $value): ISpecification
    {
        return self::getFactory()->lessThan($value);
    }

    /**
     * Especifica que o valor deve ser menor ou igual ao limite.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function lessThanOrEqualTo(mixed $value): ISpecification
    {
        return self::getFactory()->lessThanOrEqualTo($value);
    }

    /**
     * Especifica que o valor deve pertencer ao conjunto fornecido.
     *
     * @param mixed ...$values
     * @return ISpecification
     */
    public static function in(mixed ...$values): ISpecification
    {
        return self::getFactory()->in(...$values);
    }

    // ==========================================
    // 4. Comparações Temporais (Datas/Horas)
    // ==========================================

    /**
     * Especifica que a data/valor deve ser estritamente anterior.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function before(mixed $value): ISpecification
    {
        return self::getFactory()->before($value);
    }

    /**
     * Alias de before() espelhando o Java Domian.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function isBefore(mixed $value): ISpecification
    {
        return self::getFactory()->isBefore($value);
    }

    /**
     * Especifica que a data/valor deve ser estritamente posterior.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function after(mixed $value): ISpecification
    {
        return self::getFactory()->after($value);
    }

    /**
     * Alias de after() espelhando o Java Domian.
     *
     * @param mixed $value
     * @return ISpecification
     */
    public static function isAfter(mixed $value): ISpecification
    {
        return self::getFactory()->isAfter($value);
    }

    /**
     * Especifica que a data deve ser exatamente no momento fornecido.
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function at(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->at($date);
    }

    /**
     * Alias de at().
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function atTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->atTheSameTimeAs($date);
    }

    /**
     * Especifica que a data deve ser anterior ou igual ao momento fornecido.
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function beforeOrAt(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->beforeOrAt($date);
    }

    /**
     * Alias de beforeOrAt().
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function beforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->beforeOrAtTheSameTimeAs($date);
    }

    /**
     * Alias de beforeOrAt().
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function isBeforeOrAt(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->isBeforeOrAt($date);
    }

    /**
     * Alias de beforeOrAtTheSameTimeAs().
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function isBeforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->isBeforeOrAtTheSameTimeAs($date);
    }

    /**
     * Especifica que a data deve ser posterior ou igual ao momento fornecido.
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function afterOrAt(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->afterOrAt($date);
    }

    /**
     * Alias de afterOrAt().
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function afterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->afterOrAtTheSameTimeAs($date);
    }

    /**
     * Alias de afterOrAt().
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function isAfterOrAt(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->isAfterOrAt($date);
    }

    /**
     * Alias de afterOrAtTheSameTimeAs().
     *
     * @param DateTimeInterface $date
     * @return ISpecification
     */
    public static function isAfterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return self::getFactory()->isAfterOrAtTheSameTimeAs($date);
    }

    /**
     * Especifica intervalo temporal fechado [start, end].
     *
     * @param DateTimeInterface $start
     * @param DateTimeInterface $end
     * @return ISpecification
     */
    public static function between(DateTimeInterface $start, DateTimeInterface $end): ISpecification
    {
        return self::getFactory()->between($start, $end);
    }

    // ==========================================
    // 5. Constantes e Estados Especiais
    // ==========================================

    /**
     * Cria uma especificação tautológica universal que é sempre satisfeita (True).
     *
     * @return ISpecification
     */
    public static function alwaysTrue(): ISpecification
    {
        return self::getFactory()->alwaysTrue();
    }

    /**
     * Cria uma especificação de contradição que nunca é satisfeita (False).
     *
     * @return ISpecification
     */
    public static function alwaysFalse(): ISpecification
    {
        return self::getFactory()->alwaysFalse();
    }

    /**
     * Especifica que o candidato deve ser estritamente nulo (null).
     *
     * @return ISpecification
     */
    public static function isNull(): ISpecification
    {
        return self::getFactory()->isNull();
    }

    /**
     * Especifica que o candidato não pode ser nulo.
     *
     * @return ISpecification
     */
    public static function isNotNull(): ISpecification
    {
        return self::getFactory()->isNotNull();
    }

    /**
     * Especifica que o candidato deve ser estritamente booleano true.
     *
     * @return ISpecification
     */
    public static function isTrue(): ISpecification
    {
        return self::getFactory()->isTrue();
    }

    /**
     * Especifica que o candidato deve ser estritamente booleano false.
     *
     * @return ISpecification
     */
    public static function isFalse(): ISpecification
    {
        return self::getFactory()->isFalse();
    }

    /**
     * Especifica que a cadeia de caracteres deve estar vazia ou conter apenas espaços em branco.
     *
     * @return ISpecification
     */
    public static function isBlank(): ISpecification
    {
        return self::getFactory()->isBlank();
    }

    /**
     * Especifica que o candidato deve equivaler ao valor default do seu tipo (null, false, 0, vazio).
     *
     * @return ISpecification
     */
    public static function defaultValue(): ISpecification
    {
        return self::getFactory()->defaultValue();
    }

    // ==========================================
    // 6. Strings e Coleções
    // ==========================================

    /**
     * Especifica que a cadeia deve casar com a expressão regular fornecida.
     *
     * @param string $pattern Expressão regular no formato PCRE (ex.: '/^[0-9]+$/')
     * @return ISpecification
     */
    public static function matches(string $pattern): ISpecification
    {
        return self::getFactory()->matches($pattern);
    }

    /**
     * Alias para matches() para validação de expressão regular.
     *
     * @param string $pattern
     * @return ISpecification
     */
    public static function regex(string $pattern): ISpecification
    {
        return self::getFactory()->matches($pattern);
    }

    /**
     * Especifica que a cadeia deve casar com o padrão wildcard (* e ?).
     *
     * @param string $pattern
     * @return ISpecification
     */
    public static function wildcard(string $pattern): ISpecification
    {
        return self::getFactory()->matchesWildcard($pattern);
    }

    /**
     * Especifica que a cadeia deve casar com o padrão wildcard ignorando maiúsculas e minúsculas.
     *
     * @param string $pattern
     * @return ISpecification
     */
    public static function wildcardIgnoreCase(string $pattern): ISpecification
    {
        return self::getFactory()->matchesWildcardIgnoringCase($pattern);
    }

    /**
     * Alias longo para wildcardIgnoreCase().
     *
     * @param string $pattern
     * @return ISpecification
     */
    public static function wildcardExpressionMatcherIgnoreCase(string $pattern): ISpecification
    {
        return self::getFactory()->matchesWildcardIgnoringCase($pattern);
    }

    /**
     * Especifica que a cadeia deve ser idêntica ignorando maiúsculas e minúsculas.
     *
     * @param string $value
     * @return ISpecification
     */
    public static function equalIgnoreCase(string $value): ISpecification
    {
        return self::getFactory()->equalIgnoringCase($value);
    }

    /**
     * Especifica que a cadeia deve conter a subcadeia informada.
     *
     * @param string $substring Subcadeia a ser procurada
     * @param bool $caseSensitive Define se a busca diferencia maiúsculas de minúsculas
     * @return ISpecification
     */
    public static function contains(string $substring, bool $caseSensitive = true): ISpecification
    {
        return self::getFactory()->contains($substring, $caseSensitive);
    }

    /**
     * Especifica que a cadeia deve iniciar com o prefixo informado.
     *
     * @param string $prefix Prefixo esperado
     * @param bool $caseSensitive Define se a busca diferencia maiúsculas de minúsculas
     * @return ISpecification
     */
    public static function startsWith(string $prefix, bool $caseSensitive = true): ISpecification
    {
        return self::getFactory()->startsWith($prefix, $caseSensitive);
    }

    /**
     * Especifica que a cadeia deve terminar com o sufixo informado.
     *
     * @param string $suffix Sufixo esperado
     * @param bool $caseSensitive Define se a busca diferencia maiúsculas de minúsculas
     * @return ISpecification
     */
    public static function endsWith(string $suffix, bool $caseSensitive = true): ISpecification
    {
        return self::getFactory()->endsWith($suffix, $caseSensitive);
    }

    /**
     * Especifica que a coleção ou cadeia deve ser vazia.
     *
     * @return ISpecification
     */
    public static function isEmpty(): ISpecification
    {
        return self::getFactory()->isEmpty();
    }

    /**
     * Especifica que o tamanho da coleção deve satisfazer a especificação fornecida.
     *
     * @param ISpecification $sizeSpecification Especificação aplicada à contagem de elementos
     * @return ISpecification
     */
    public static function hasSize(ISpecification $sizeSpecification): ISpecification
    {
        return self::getFactory()->hasSize($sizeSpecification);
    }

    /**
     * Especifica que o comprimento da cadeia deve satisfazer a especificação fornecida.
     *
     * @param ISpecification $lengthSpecification Especificação aplicada ao número de caracteres
     * @return ISpecification
     */
    public static function hasLength(ISpecification $lengthSpecification): ISpecification
    {
        return self::getFactory()->hasLength($lengthSpecification);
    }

    /**
     * Especifica que a string deve corresponder ao nome de um case em um Enum PHP 8+.
     *
     * @param class-string $enumClass A classe do enum nativo.
     * @return ISpecification
     */
    public static function enumCase(string $enumClass): ISpecification
    {
        return self::getFactory()->enumCase($enumClass);
    }

    // ==========================================
    // 7. Dynamic Rule Engine
    // ==========================================

    /**
     * Cria e instancia um DynamicSpecificationEngine pronto para orquestração de regras.
     *
     * @param \Antevemus\ASpecification\Contracts\Engine\IRuleCatalog|null $catalog Catálogo de regras
     * @param \Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry|null $registry Registro de handlers
     * @return \Antevemus\ASpecification\Engine\DynamicSpecificationEngine
     */
    public static function engine(
        ?\Antevemus\ASpecification\Contracts\Engine\IRuleCatalog $catalog = null,
        ?\Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry $registry = null
    ): \Antevemus\ASpecification\Engine\DynamicSpecificationEngine {
        return new \Antevemus\ASpecification\Engine\DynamicSpecificationEngine(
            $catalog ?? new \Antevemus\ASpecification\Engine\InMemoryRuleCatalog(),
            $registry ?? new \Antevemus\ASpecification\Engine\RuleSpecificationRegistry()
        );
    }

    /**
     * Cria uma nova instância de RuleSpecificationRegistry para registro de handlers.
     *
     * @return \Antevemus\ASpecification\Engine\RuleSpecificationRegistry
     */
    public static function ruleRegistry(): \Antevemus\ASpecification\Engine\RuleSpecificationRegistry
    {
        return new \Antevemus\ASpecification\Engine\RuleSpecificationRegistry();
    }

    // ==========================================
    // 8. SQL Parser & Query Visitor (Multi-SGBD)
    // ==========================================

    /**
     * Traduz uma especificação em uma cláusula WHERE parametrizada (Multi-SGBD).
     *
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification Especificação a traduzir
     * @param \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect Dialeto alvo (pgsql, mysql, sqlsrv, oracle, firebird, etc.)
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Mapeamento opcional de propriedades para colunas
     * @return \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause
     */
    public static function toSql(
        \Antevemus\ASpecification\Contracts\ISpecification $specification,
        \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect = 'ansi',
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null
    ): \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause {
        return (new \Antevemus\ASpecification\Sql\SqlQueryVisitor($dialect, $fieldMap))->translate($specification);
    }

    /**
     * Cria uma instância de SqlQueryVisitor configurada para o dialeto e mapeamento fornecidos.
     *
     * @param \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap
     * @return \Antevemus\ASpecification\Sql\SqlQueryVisitor
     */
    public static function sqlVisitor(
        \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect = 'ansi',
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null
    ): \Antevemus\ASpecification\Sql\SqlQueryVisitor {
        return new \Antevemus\ASpecification\Sql\SqlQueryVisitor($dialect, $fieldMap);
    }

    // ==========================================
    // 9. TCriteria Builder (Adianti Database Bridge)
    // ==========================================

    /**
     * Traduz uma especificação em um objeto TCriteria do Adianti Framework.
     *
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification Especificação a traduzir
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Mapeamento opcional de propriedades para colunas
     * @param array<string, mixed> $properties Propriedades como 'order', 'limit', 'offset', 'direction', 'group'
     * @return \Adianti\Database\TCriteria
     */
    public static function toCriteria(
        \Antevemus\ASpecification\Contracts\ISpecification $specification,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        array $properties = []
    ): mixed {
        return \Antevemus\ASpecification\Criteria\TCriteriaBuilder::fromSpecification($specification, $fieldMap, $properties);
    }

    /**
     * Cria uma instância de TCriteriaBuilder para compilação fluente.
     *
     * @param \Antevemus\ASpecification\Contracts\ISpecification $specification
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap
     * @return \Antevemus\ASpecification\Criteria\TCriteriaBuilder
     */
    public static function criteriaBuilder(
        \Antevemus\ASpecification\Contracts\ISpecification $specification,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null
    ): \Antevemus\ASpecification\Criteria\TCriteriaBuilder {
        return new \Antevemus\ASpecification\Criteria\TCriteriaBuilder($specification, $fieldMap);
    }

    // ==========================================
    // 10. Fallback Dinâmico
    // ==========================================

    /**
     * Redireciona chamadas estáticas não explicitamente definidas para a SpecificationFactory.
     *
     * @param string $name
     * @param array<mixed> $arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        return self::getFactory()->$name(...$arguments);
    }
}
