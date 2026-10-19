# Partial Satisfaction (`remainderUnsatisfiedBy`)

### 3. Partial Satisfaction (`remainderUnsatisfiedBy`)

Pinpoint at runtime precisely which sub-rules failed for a given candidate:

```php
$onboardingSpec = Spec::specify(User::class)
    ->where('emailVerified', Spec::isTrue())
    ->and('termsAccepted', Spec::isTrue())
    ->and('profileComplete', Spec::isTrue());

$remainder = $onboardingSpec->remainderUnsatisfiedBy($user);

if ($remainder !== null) {
    // $remainder contains ONLY the specific clauses not yet satisfied!
    echo "Pending onboarding requirements: " . (string) $remainder;
}
```
