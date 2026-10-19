# Zero-Exception Notification Pattern & Diagnostic Telemetry

### 2. Zero-Exception Notification Pattern & Diagnostic Telemetry

In enterprise workflows, REST APIs, and UI forms, use `evaluate()` to accumulate all validation failures without interrupting execution:

```php
$adultSpec = Spec::property('age', Spec::greaterThanOrEqualTo(18))
    ->because('Customer must be of legal age.')
    ->withCode('CLI_001');

$activeSpec = Spec::property('status', Spec::equalTo('ACTIVE'))
    ->because('Only active accounts can receive a credit line.')
    ->withCode('CLI_002');

$creditRule = $adultSpec->and($activeSpec);

$result = $creditRule->evaluate($customer);

if (!$result->isSatisfied) {
    echo "Validation failed (" . count($result->failures) . " errors found):\n";
    foreach ($result->failures as $failure) {
        echo sprintf(" - [%s] %s: %s\n", $failure->code, $failure->property, $failure->message);
    }
}
```

**Evaluation errors and what to show.** When a rule cannot be evaluated (a specification, a handler or a guard throws), `isError` is `true`, the failure carries the generic message `SpecificationResult::EVALUATION_ERROR_MESSAGE` with the rule's code, and the exception stays on `$result->exception`, never in the failures. The library does not log for you. Recommended practice: send `$result->exception` to your logger, taking care not to log more than needed (exception messages can hold credentials, personal data or SQL); show users only `$failure->message`, which is either your business message or the generic one; and never show `metadata`, which is diagnostic and may hold the evaluated value (`metadata['actual']`).

---

Since v2.5.0 `labelled($label)` reports the failures of a specification against a text built for the user: `property` becomes the label, and the technical property is kept in `metadata['property_path']` (example 22).
