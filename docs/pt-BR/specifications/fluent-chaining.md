# Fluent Chaining em Linguagem Natural

### 1. Fluent Chaining em Linguagem Natural

Inspirado na sintaxe do Domian em Java, combine regras encadeadas de forma limpa e intuitiva:

```php
use function Antevemus\ASpecification\DSL\specify;
use function Antevemus\ASpecification\DSL\is;
use function Antevemus\ASpecification\DSL\not;
use function Antevemus\ASpecification\DSL\isBefore;
use function Antevemus\ASpecification\DSL\isAfterOrAt;
use function Antevemus\ASpecification\DSL\anyOf;

// Especificação: Clientes do sexo feminino associados há mais de 1 ano,
// OU clientes masculinos com mais de 10 anos de idade.
$loyalFemale = specify(Customer::class)
    ->where('gender', is('FEMALE'))
    ->and('membershipDate', isBefore($oneYearAgo));

$seniorMale = specify(Customer::class)
    ->where('gender', is('MALE'))
    ->and('birthDate', not(isAfterOrAt($tenYearsAgo)));

$spec = anyOf($loyalFemale, $seniorMale);

// Avaliação booleana tradicional
if ($spec->isSatisfiedBy($customer)) {
    // Regra atendida com sucesso!
}
```

### 1.1. Folhas Inteiras: `asInteger()` e `Spec::int()` (Módulo 44)

Desde a v2.9.0 uma folha relacional pode ser tipada como inteira. `asInteger()` devolve uma cópia da folha cujo `getType()` é `'int'`: ela recusa com `IncompatibleTypeException` todo candidato que não seja int (um float, inclusive `12.0`, um texto numérico, um booleano), como `declare(strict_types=1)` recusa num parâmetro `int`, e o limite dela precisa ser int (`greaterThan(10.5)->asInteger()` e `greaterThan(10.0)->asInteger()` são recusados com `InvalidArgumentException`). `null` mantém a resposta da folha. `Spec::int()` é a mesma coisa para uma regra inteira sobre inteiros: `greaterThan()`, `greaterThanOrEqualTo()`, `lessThan()`, `lessThanOrEqualTo()`, `between(int, int)`, `equalTo()`, `notEqualTo()` e `in()`, cada uma a folha da fábrica geral tornada inteira. A conversão é da fronteira de entrada (um mapeador, um formulário, `json_decode()`, que já devolve int para `12`), não da regra.

```php
use Antevemus\ASpecification\Spec;
use function Antevemus\ASpecification\DSL\greaterThanOrEqualTo;

// Uma regra sobre meses inteiros: a folha inteira recusa o que não é int
$minimumPeriod = Spec::int()->greaterThanOrEqualTo(12);          // o mesmo que Spec::greaterThanOrEqualTo(12)->asInteger()
$minimumPeriod->getType();                                       // 'int'
$minimumPeriod->isSatisfiedBy(12);                               // true
$minimumPeriod->isSatisfiedBy(6);                                // false
$minimumPeriod->evaluate(12.0)->isError;                         // true: um float, mesmo sem parte fracionária
$minimumPeriod->evaluate(10.5)->isError;                         // true
$minimumPeriod->evaluate('12')->isError;                         // true: um texto numérico; converta onde ele é lido
greaterThanOrEqualTo(12)->asInteger()->equals($minimumPeriod);   // true: a função do DSL também devolve a folha
Spec::greaterThanOrEqualTo(12)->equals($minimumPeriod);          // false: essa aceita 12.5

$rule = Spec::property('adjustmentPeriodMonths', $minimumPeriod)
    ->and(Spec::property('installments', Spec::int()->between(1, 12)));
$rule->isSatisfiedBy((object) ['adjustmentPeriodMonths' => 12, 'installments' => 10]);   // true
$rule->evaluate((object) ['adjustmentPeriodMonths' => '12', 'installments' => 10])->isError; // true
Spec::toSql($rule, 'pgsql')->getSql();                           // o SQL da mesma regra sem o modo inteiro
```

- **Os tipos de retorno.** `Spec::equalTo()`, `equal()`, `notEqual()`, `greaterThan()`, `greaterThanOrEqualTo()`, `lessThan()` e `lessThanOrEqualTo()`, as funções do DSL de mesmo nome e esses métodos de `Spec::getFactory()` declaram a classe da folha (`GreaterThanSpecification`, ...) em vez de `ISpecification`. Tipos de retorno são covariantes, então nenhum chamador quebra, e a IDE e a análise estática passam a enxergar `asInteger()`. As interfaces das fábricas (`IComparisonSpecificationFactory`) não mudam, então as implementações delas também não quebram.
- **As traduções não mudam.** O SQL de todo dialeto, o `TCriteria` e o filtro ALinq de uma folha inteira são os da mesma folha sem o modo: a coluna do banco já guarda inteiros. O ALinq avalia a própria folha, então uma coleção com `12.0` lança como `isSatisfiedBy()` lança.
- **A álgebra.** Duas folhas inteiras fecham os limites entre si (`x > 10` é `x >= 11`); o exemplo 12.1 mostra o que isso devolve. Uma folha inteira serializada leva `"int": true` (`{"t": "gte", "v": 12, "int": true}`, exemplo 21) e é lida de volta inteira.
