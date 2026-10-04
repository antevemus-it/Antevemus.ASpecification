<?php

namespace Antevemus\ASpecification\Contracts;

use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * ISpecification interface.
 *
 * Part of the Evans/Fowler Specifications pattern.
 *
 * Interface que define o contrato para implementação do padrão de design Specification.
 * O padrão Specification permite encapsular regras de negócio em objetos reutilizáveis
 * que podem ser combinados usando operadores lógicos (AND, OR, NOT).
 *
 * Note on type parameterization:
 * Domain specifications are typed. It is only relevant to send candidate objects of
 * correct type to a Specification for approval. PHP generics (via PHPDoc) should be
 * used when creating specifications, making this specification type vs. candidate type
 * a static analysis issue.
 *
 * Este padrão é especialmente útil para:
 * - Validação de objetos complexos
 * - Filtragem e seleção de objetos em coleções
 * - Construção de consultas dinâmicas
 * - Separação de lógica de negócio da lógica de acesso a dados
 * - Análise de subsunção entre especificações
 *
 * @template T
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 * @see        https://www.martinfowler.com/apsupp/spec.pdf The Specifications Pattern
 */
interface ISpecification
{

    /**
     * Cria uma especificação parametrizada baseada em um campo/propriedade acessível do objeto.
     *
     * Este método é um alias do método 'and' e permite criar especificações fluentes
     * baseadas em propriedades específicas do objeto candidato.
     *
     * Exemplo:
     * <code>
     * $spec = $baseSpec->where('address', new CitySpecification('São Paulo'))
     *                  ->and(new AgeSpecification(18));
     * </code>
     *
     * @template F
     * @param string $accessibleObjectName Nome da propriedade/método acessível a ser especificado
     * @param ISpecification<F> $accessibleObjectSpecification Especificação acoplada ao objeto acessível
     * @return ICompositeSpecification<T> Uma conjunção desta especificação com a especificação do objeto acessível
     * @throws \InvalidArgumentException Se qualquer um dos parâmetros for null
     * @throws \BadMethodCallException Se este método for chamado duas vezes na mesma expressão (restrição de interface fluente)
     */
    public function where(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification;

    /**
     * Cria uma conjunção (AND lógico) de duas especificações.
     *
     * Combina esta especificação com outra usando o operador lógico AND.
     * A especificação resultante só é satisfeita quando AMBAS as especificações
     * (esta e a outra) são satisfeitas.
     *
     * Exemplo:
     * <code>
     * $adultSpec = new AgeSpecification(18);
     * $verifiedSpec = new EmailVerifiedSpecification();
     * $combined = $adultSpec->and($verifiedSpec);
     * </code>
     *
     * @param ISpecification<T> $otherSpecification A outra especificação a ser combinada
     * @return ICompositeSpecification<T> Nova especificação composta: esta especificação AND a outra especificação
     * @throws \InvalidArgumentException Se o parâmetro for null
     * @throws \InvalidArgumentException Se o tipo do objeto acessível e o tipo da especificação não forem compatíveis
     * @throws \BadMethodCallException Se este método não for colocado após uma cláusula 'where' (restrição de interface fluente)
     */
    public function and(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification;

    /**
     * Cria uma disjunção (OR lógico) de duas especificações.
     *
     * Combina esta especificação com outra usando o operador lógico OR.
     * Suporta tanto composição de especificações quanto encadeamento fluente de propriedades.
     *
     * Exemplo:
     * <code>
     * $adminSpec = new RoleSpecification('admin');
     * $ownerSpec = new OwnerSpecification($userId);
     * $hasAccess = $adminSpec->or($ownerSpec);
     * // Ou parametrizado:
     * $spec->or('gender', Spec::is('MALE'));
     * </code>
     *
     * @param ISpecification<T>|string $otherSpecification A outra especificação ou nome da propriedade
     * @param ISpecification<mixed>|null $propertySpecification A especificação da propriedade (quando o 1º argumento for string)
     * @return ICompositeSpecification<T> Nova especificação composta: esta especificação OR a outra especificação
     * @throws \InvalidArgumentException Se o parâmetro for null
     */
    public function or(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification;

    /**
     * Inverte esta especificação usando o operador lógico NOT.
     *
     * Cria uma nova especificação que é satisfeita quando esta
     * especificação NÃO é satisfeita, e vice-versa.
     *
     * Exemplo:
     * <code>
     * $adultSpec = new AgeSpecification(18);
     * $minorSpec = $adultSpec->not();
     * </code>
     *
     * @return ICompositeSpecification<T> Nova especificação que representa a negação lógica
     */
    public function not(): ICompositeSpecification;

    /**
     * Aceita um visitor para percorrer a árvore de especificações (Visitor Pattern).
     *
     * @template TResult
     * @param ISpecificationVisitor $visitor O visitor a ser aceito
     * @return mixed O resultado produzido pelo visitor
     */
    public function accept(ISpecificationVisitor $visitor): mixed;

    /**
     * Traduz esta especificação em uma cláusula WHERE parametrizada para banco de dados relacional.
     *
     * @param \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect Dialeto alvo (ex: 'pgsql', 'mysql', 'sqlsrv', 'oracle', 'firebird')
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Mapeamento opcional de propriedades para colunas
     * @return \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause
     */
    public function toSql(
        \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect = 'ansi',
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null
    ): \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause;

    /**
     * Traduz esta especificação em um objeto TCriteria do Adianti Framework.
     *
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Mapeamento opcional de propriedades para colunas
     * @param array<string, mixed> $properties Propriedades como 'order', 'limit', 'offset', 'direction', 'group'
     * @return mixed Instância de \Adianti\Database\TCriteria
     */
    public function toCriteria(
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        array $properties = []
    ): mixed;

    /**
     * Retorna o tipo (classe) do objeto candidato que esta especificação valida.
     *
     * Em PHP, este método retorna o nome completo da classe (FQCN) que esta
     * especificação está preparada para validar.
     *
     * @return class-string<T> O nome completo da classe do tipo da especificação
     */
    public function getType(): string;

    /**
     * Verifica se o candidato satisfaz a especificação (Specification satisfaction).
     *
     * Este método é o núcleo do padrão Specification. Ele recebe um objeto
     * candidato e retorna verdadeiro se o objeto satisfaz a regra de negócio
     * encapsulada pela especificação, ou falso caso contrário.
     *
     * Importante: null nunca é aprovado por uma especificação.
     *
     * @param T|null $candidate O objeto candidato a ser validado
     * @return bool Retorna true se o candidato satisfaz a especificação, false caso contrário (null sempre retorna false)
     */
    public function isSatisfiedBy(?object $candidate): bool;

    /**
     * Verifica subsunção de especificação (Specification subsumption) - Generalização.
     *
     * Dado:
     * - Conjunto K contendo candidatos especificados por specA: specA->isSatisfiedBy($candidate)
     * - Conjunto L contendo candidatos especificados por specB: specB->isSatisfiedBy($candidate)
     *
     * Então:
     * Se specA->isGeneralizationOf(specB) => Conjunto K contém Conjunto L [K ∪ L = K]
     *
     * Em outras palavras, se esta especificação é uma generalização da outra,
     * então todo objeto aprovado pela outra especificação também será aprovado por esta.
     *
     * Exemplo:
     * <code>
     * $animalSpec = new TypeSpecification(Animal::class);
     * $dogSpec = new TypeSpecification(Dog::class);
     * $animalSpec->isGeneralizationOf($dogSpec); // true - Animal é mais geral que Dog
     * </code>
     *
     * @param ISpecification<T> $otherSpecification A especificação candidata
     * @return bool True se esta especificação é uma generalização da especificação candidata
     * @throws \InvalidArgumentException Se o parâmetro for null
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool;

    /**
     * Verifica subsunção de especificação (Specification subsumption) - Especialização.
     *
     * Dado:
     * - Conjunto K contendo candidatos especificados por specA: specA->isSatisfiedBy($candidate)
     * - Conjunto L contendo candidatos especificados por specB: specB->isSatisfiedBy($candidate)
     *
     * Então:
     * Se specA->isSpecialCaseOf(specB) => Conjunto L contém Conjunto K [K ∪ L = L]
     *
     * Em outras palavras, se esta especificação é um caso especial da outra,
     * então todo objeto aprovado por esta também será aprovado pela outra.
     *
     * Exemplo:
     * <code>
     * $dogSpec = new TypeSpecification(Dog::class);
     * $animalSpec = new TypeSpecification(Animal::class);
     * $dogSpec->isSpecialCaseOf($animalSpec); // true - Dog é caso especial de Animal
     * </code>
     *
     * @param ISpecification<T> $otherSpecification A especificação candidata
     * @return bool True se esta especificação é um caso especial da especificação candidata
     * @throws \InvalidArgumentException Se o parâmetro for null
     */
    public function isSpecialCaseOf(ISpecification $otherSpecification): bool;

    /**
     * Verifica se duas especificações são disjuntas (não possuem objetos em comum).
     *
     * Duas especificações são disjuntas se os dois conjuntos de objetos satisfatórios
     * não possuem objetos em comum (interseção vazia).
     *
     * Exemplo:
     * <code>
     * $adultSpec = new AgeGreaterThanSpecification(18);
     * $childSpec = new AgeLessThanSpecification(12);
     * $adultSpec->isDisjointWith($childSpec); // true - não há sobreposição
     * </code>
     *
     * @param ISpecification<mixed> $otherSpecification A especificação candidata
     * @return bool True se esta especificação é disjunta com a especificação candidata
     * @throws \InvalidArgumentException Se o parâmetro for null
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool;

    /**
     * Verifica se esta especificação representa a interseção com outra especificação.
     *
     * Uma especificação é uma interseção de outra se o conjunto de objetos satisfatórios
     * desta especificação é exatamente a interseção dos conjuntos de objetos satisfatórios
     * de duas outras especificações.
     *
     * Em outras palavras, verifica se esta especificação é semanticamente equivalente
     * a uma operação AND de outras especificações.
     *
     * Exemplo:
     * <code>
     * $ageRange = new AgeRangeSpecification(18, 65); // idade entre 18 e 65
     * $minAge = new AgeGreaterThanSpecification(18);
     * $maxAge = new AgeLessThanSpecification(65);
     * $combined = $minAge->and($maxAge);
     * $ageRange->isIntersectionOf($combined); // Pode retornar true se forem semanticamente equivalentes
     * </code>
     *
     * @param ISpecification<T> $otherSpecification A especificação candidata
     * @return bool True se esta especificação é uma interseção da especificação candidata
     * @throws \InvalidArgumentException Se o parâmetro for null
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool;

    /**
     * Verifica se esta especificação possui interseção não-vazia com outra especificação.
     *
     * Duas especificações possuem interseção se existe pelo menos um objeto que
     * satisfaz ambas as especificações. Este método é o oposto lógico de isDisjointWith().
     *
     * Exemplo:
     * <code>
     * $adultSpec = new AgeGreaterThanSpecification(18); // idade > 18
     * $youngSpec = new AgeLessThanSpecification(30);    // idade < 30
     * $adultSpec->intersectsWith($youngSpec); // true - pessoas entre 18 e 30 satisfazem ambas
     *
     * $childSpec = new AgeLessThanSpecification(12);    // idade < 12
     * $adultSpec->intersectsWith($childSpec); // false - nenhum objeto satisfaz ambas
     * </code>
     *
     * @param ISpecification<mixed> $otherSpecification A especificação candidata
     * @return bool True se existe pelo menos um objeto que satisfaz ambas as especificações
     * @throws \InvalidArgumentException Se o parâmetro for null
     */
    public function intersectsWith(ISpecification $otherSpecification): bool;

    /**
     * Avalia o candidato retornando um objeto rico de resultado (Notification Pattern).
     *
     * Ao contrário de isSatisfiedBy() que retorna um booleano simples, este método
     * provê rastreabilidade completa das falhas, mensagens amigáveis e códigos de erro.
     *
     * @param mixed $candidate Objeto ou valor candidato a ser avaliado
     * @return SpecificationResult Resultado da avaliação com detalhes de aprovação ou falhas
     */
    public function evaluate(mixed $candidate): SpecificationResult;

    /**
     * Define uma mensagem amigável personalizada para explicar o motivo de uma eventual falha.
     *
     * @param string $reason Mensagem explicando a finalidade ou a violação da regra
     * @return static Nova instância enriquecida com a razão descrita
     */
    public function because(string $reason): static;

    /**
     * Define um código identificador (regulatório, legal ou de negócio) associado a esta regra.
     *
     * @param string $code Código identificador do erro (ex.: 'INQ_004', 'APOL_002')
     * @return static Nova instância enriquecida com o código especificado
     */
    public function withCode(string $code): static;

    /**
     * Cria uma conjunção negada (AND NOT lógico) de duas especificações.
     *
     * Atalho idiomático fluente equivalente a $this->and($otherSpecification->not()).
     *
     * @param ISpecification<T> $otherSpecification A especificação cuja negação será exigida
     * @return ICompositeSpecification<T> Nova especificação composta: esta AND NOT outra
     */
    public function andNot(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification;

    /**
     * Cria uma disjunção com negação (OR NOT lógico) de duas especificações.
     *
     * Atalho idiomático fluente equivalente a $this->or($otherSpecification->not()).
     *
     * @param ISpecification<T>|string $otherSpecification A especificação cuja negação será aceita ou nome da propriedade
     * @param ISpecification<mixed>|null $propertySpecification A especificação da propriedade (quando o 1º argumento for string)
     * @return ICompositeSpecification<T> Nova especificação composta: esta OR NOT outra
     */
    public function orNot(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification;
}
