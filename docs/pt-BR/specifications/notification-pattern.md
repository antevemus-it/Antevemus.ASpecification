# Notification Pattern & Diagnóstico Rico (Sem Exceções)

### 2. Notification Pattern & Diagnóstico Rico (Sem Exceções)

Em cenários corporativos e validação de formulários/APIs, use `evaluate()` para capturar todas as violações sem interromper o fluxo com exceções:

```php
$adultSpec = Spec::property('age', Spec::greaterThanOrEqualTo(18))
    ->because('O cliente deve ter atingido a maioridade legal.')
    ->withCode('CLI_001');

$activeSpec = Spec::property('status', Spec::equalTo('ACTIVE'))
    ->because('Apenas cadastros ativos podem receber crédito.')
    ->withCode('CLI_002');

$approvalRule = $adultSpec->and($activeSpec);

$result = $approvalRule->evaluate($customer);

if (!$result->isSatisfied) {
    echo "Falha na validação (" . count($result->failures) . " erros encontrados):\n";
    foreach ($result->failures as $failure) {
        echo sprintf(" - [%s] %s: %s\n", $failure->code, $failure->property, $failure->message);
    }
}
```

**Erros de avaliação e o que mostrar.** Quando uma regra não pode ser avaliada (uma especificação, um handler ou um guard lança), `isError` é `true`, a falha traz a mensagem genérica `SpecificationResult::EVALUATION_ERROR_MESSAGE` com o código da regra, e a exceção fica em `$result->exception`, nunca nas falhas. A biblioteca não faz log por você. Boa prática: mande `$result->exception` para o seu logger, com cuidado para não registrar mais do que o necessário (mensagens de exceção podem conter credenciais, dados pessoais ou SQL); mostre ao usuário só `$failure->message`, que é a sua mensagem de negócio ou a genérica; e nunca mostre a `metadata`, que é diagnóstica e pode conter o valor avaliado (`metadata['actual']`).

---

Desde a v2.5.0 o `labelled($label)` reporta as falhas de uma especificação contra um texto feito para o usuário: `property` vira o rótulo, e a propriedade técnica fica em `metadata['property_path']` (exemplo 22).
