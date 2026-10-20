# Satisfação Parcial (`remainderUnsatisfiedBy`)

### 3. Satisfação Parcial (`remainderUnsatisfiedBy`)

Descubra exatamente qual subconjunto de regras falhou para uma entidade:

```php
$onboardingSpec = Spec::specify(User::class)
    ->where('emailVerified', Spec::isTrue())
    ->and('termsAccepted', Spec::isTrue())
    ->and('profileComplete', Spec::isTrue());

$remainder = $onboardingSpec->remainderUnsatisfiedBy($user);

if ($remainder !== null) {
    // $remainder contém APENAS as cláusulas não atendidas pelo usuário!
    echo "Pending onboarding requirements: " . (string) $remainder;
}
```
