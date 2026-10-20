# Attributes Declarativos no PHP 8 (`#[AssertSpec]`, `#[ValidateRule]`)

### 11. Attributes Declarativos no PHP 8 (`#[AssertSpec]`, `#[ValidateRule]`) (Módulo 15)

No PHP 8.2+, anote Data Transfer Objects (DTOs), Form Requests, Value Objects e Entidades diretamente com especificações:

```php
use Antevemus\ASpecification\Attributes\AssertSpec;
use Antevemus\ASpecification\Attributes\ValidateRule;
use Antevemus\ASpecification\Spec;

#[AssertSpec(CustomerMustBeActiveSpec::class, message: 'Customer account is suspended', code: 'CUST_SUSPENDED')]
class RegisterCustomerDto
{
    #[ValidateRule('not_blank', message: 'Name cannot be empty')]
    public string $name;

    #[ValidateRule('>=', value: 18, message: 'Customer must be at least 18 years old', code: 'UNDERAGE')]
    public int $age;

    #[ValidateRule('email', message: 'Invalid corporate email format')]
    public string $email;

    public function __construct(string $name, int $age, string $email)
    {
        $this->name = $name;
        $this->age = $age;
        $this->email = $email;
    }
}

$dto = new RegisterCustomerDto('Alice Smith', 16, 'alice@example.com');

// 1. Validação com Notification Pattern (sem exceções)
$result = Spec::validateAttributes($dto);
if (!$result->isSatisfied) {
    foreach ($result->failures as $failure) {
        echo "Violation [{$failure->code}]: {$failure->message} (at {$failure->ruleName})\n";
    }
}

// 2. Asserção estrita com exceção
try {
    Spec::assertAttributes($dto);
} catch (\Antevemus\ASpecification\Attributes\Exceptions\AttributeValidationException $e) {
    // Lançada automaticamente em caso de falha com detalhes completos
    $failures = $e->getResult()->failures;
}
```
