# Chamadas de Método Declarativas (`MethodParameterizedSpecification`)

### 13. Chamadas de Método Declarativas (`MethodParameterizedSpecification`)

`where()` lê uma propriedade; `whereMethod()` / `Spec::calling()` chamam um método público do candidato com argumentos declarados como dados e aplicam uma especificação ao resultado. Diferente de `must(closure)`, nome do método e argumentos são dados: a especificação tem `equals()` estrutural, nome de regra e pode ser descrita num catálogo. Método ausente é erro de avaliação, nunca um `false` silencioso.

```php
use Antevemus\ASpecification\Spec;

final class Contract
{
    public function __construct(private DateTimeImmutable $endsAt, private int $installments) {}
    public function isEligibleFor(DateTimeInterface $date): bool { return $date <= $this->endsAt; }
    public function installmentsLeft(int $paid): int { return $this->installments - $paid; }
}

$eligible = Spec::specify(Contract::class)
    ->whereMethod('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())
    ->and(Spec::calling('installmentsLeft', [3], Spec::greaterThan(0)));

$eligible->isSatisfiedBy(new Contract(new DateTimeImmutable('2027-06-30'), 12)); // true
$eligible->isSatisfiedBy(new Contract(new DateTimeImmutable('2026-06-30'), 12)); // false (not eligible)
$eligible->isSatisfiedBy(new Contract(new DateTimeImmutable('2027-06-30'), 3));  // false (nothing left)

// Declarative: method name and arguments are data, so equality is structural
Spec::calling('installmentsLeft', [3], Spec::greaterThan(0))
    ->equals(Spec::calling('installmentsLeft', [3], Spec::greaterThan(0)));      // true

// A missing method is an evaluation error, never a silent false
Spec::calling('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())
    ->evaluate(new stdClass())->isError;                                          // true
```

Os visitors SQL e TCriteria recusam a chamada de método como folha não traduzível (como `must()`); o visitor ALinq a compila, e desde a v2.4.0 o `SpecificationCodec` a grava como dado (`"t": "method"`, exemplo 21). Um argumento de data é passado pelo instante (desde a v2.1.0): o método recebe uma cópia da mesma classe no mesmo instante, no fuso padrão do PHP (`date_default_timezone_get()`), qualquer que seja o fuso em que foi declarado, então duas chamadas que o `equals()` declara iguais também respondem igual.
