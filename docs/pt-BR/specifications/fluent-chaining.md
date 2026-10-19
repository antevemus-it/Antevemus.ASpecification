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
