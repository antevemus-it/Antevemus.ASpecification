<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Attributes\AssertSpec;
use Antevemus\ASpecification\Attributes\AttributeValidator;
use Antevemus\ASpecification\Attributes\Exceptions\AttributeValidationException;
use Antevemus\ASpecification\Attributes\Exceptions\UnknownRuleOperatorException;
use Antevemus\ASpecification\Attributes\Exceptions\UnknownSpecificationClassException;
use Antevemus\ASpecification\Attributes\ValidateRule;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\TestCase;

// =============================================================================
// Fixture Specifications & DTOs for Declarative Attribute Testing
// =============================================================================

class IsAdultSpecification extends AbstractSpecification
{
    public function getType(): string
    {
        return 'int';
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_numeric($candidate) && $candidate >= 18;
    }
}

class MinimumLengthSpecification extends AbstractSpecification
{
    public function __construct(private int $min = 3)
    {
    }

    public function getType(): string
    {
        return 'string';
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_string($candidate) && mb_strlen($candidate) >= $this->min;
    }
}

class ValidOrganizationTaxIdSpec extends AbstractSpecification
{
    public function getType(): string
    {
        return 'string';
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_string($candidate) && (bool) preg_match('/^\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}$/', $candidate);
    }
}

#[AssertSpec(ValidCustomerAggregateSpec::class, code: 'AGG_001', message: 'Aggregate state is inconsistent')]
class CustomerRegistrationDto
{
    #[AssertSpec(MinimumLengthSpecification::class, code: 'NAME_LEN', message: 'Name must have at least 3 chars', arguments: [3])]
    #[ValidateRule(operator: 'notEmpty', code: 'NAME_REQ', message: 'Name is required')]
    public string $name;

    #[AssertSpec(IsAdultSpecification::class, code: 'CUST_AGE', message: 'Customer must be an adult')]
    #[ValidateRule(operator: 'between', expected: [18, 120], code: 'AGE_RANGE')]
    public int $age;

    #[ValidateRule(operator: 'email', code: 'CUST_EMAIL', message: 'Invalid email address')]
    public string $email;

    #[ValidateRule(operator: '>=', expected: 0.0, code: 'CREDIT_LIMIT')]
    public float $creditLimit;

    #[ValidateRule(operator: 'in', expected: ['STANDARD', 'PREMIUM', 'VIP'], code: 'TIER_INVALID')]
    public string $tier;

    #[AssertSpec(ValidOrganizationTaxIdSpec::class, code: 'TAX_ID')]
    public ?string $cnpj = null;

    public function __construct(
        string $name,
        int $age,
        string $email,
        float $creditLimit = 1000.0,
        string $tier = 'STANDARD',
        ?string $cnpj = '12.345.678/0001-90'
    ) {
        $this->name = $name;
        $this->age = $age;
        $this->email = $email;
        $this->creditLimit = $creditLimit;
        $this->tier = $tier;
        $this->cnpj = $cnpj;
    }

    #[ValidateRule(operator: '>', expected: 0, code: 'TOTAL_SCORE')]
    public function getComputedScore(): int
    {
        return $this->age * 10;
    }
}

class ValidCustomerAggregateSpec extends AbstractSpecification
{
    public function getType(): string
    {
        return CustomerRegistrationDto::class;
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!$candidate instanceof CustomerRegistrationDto) {
            return false;
        }
        return $candidate->creditLimit <= 50000.0;
    }
}

// README example 11 fixtures (Forward 014). The README does not define CustomerMustBeActiveSpec.
class CustomerMustBeActiveSpec extends AbstractSpecification
{
    public function getType(): string
    {
        return 'object';
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return true;
    }
}

#[AssertSpec(CustomerMustBeActiveSpec::class, message: 'Customer account is suspended', code: 'CUST_SUSPENDED')]
class Readme11RegisterCustomerDto
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

class Readme11NotBlankHolder
{
    #[ValidateRule('not_blank', code: 'BLANK')]
    public mixed $value;

    public function __construct(mixed $value)
    {
        $this->value = $value;
    }
}

// BUG #24 (unknown specification class) and BUG #25 (evaluation error) fixtures.
class UnavailableServiceSpec extends AbstractSpecification
{
    public function getType(): string
    {
        return 'mixed';
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        throw new \RuntimeException('tariff service unavailable');
    }
}

class NotASpecificationClass
{
}

#[AssertSpec('App\Specs\DoesNotExist', code: 'AGG_001')]
class UnknownSpecOnClassDto
{
    public int $x = 1;
}

class UnknownSpecOnGetterDto
{
    #[AssertSpec('App\Specs\ScoreSpecificaton', code: 'SCORE')]
    public function getScore(): int
    {
        return 10;
    }
}

class EvaluationErrorDto
{
    #[AssertSpec(UnavailableServiceSpec::class, code: 'TARIFF', message: 'Tariff must be valid')]
    public int $tariff = 10;

    #[AssertSpec(IsAdultSpecification::class, code: 'CUST_AGE', message: 'Customer must be an adult')]
    public int $age = 5;
}

#[AssertSpec(UnavailableServiceSpec::class, code: 'AGG_ERR')]
class EvaluationErrorOnClassDto
{
    public int $x = 1;
}

class EvaluationErrorOnGetterDto
{
    #[AssertSpec(UnavailableServiceSpec::class, code: 'SCORE_ERR')]
    public function getScore(): int
    {
        return 1;
    }
}

// BUG-20261007-MXUG (#30): the annotated getter itself throws when invoked (before any evaluation)
class ThrowingAssertSpecGetterDto
{
    #[AssertSpec(IsAdultSpecification::class, code: 'AGE_ERR', message: 'Customer must be an adult')]
    public function getAge(): int
    {
        throw new \RuntimeException('boom');
    }

    #[AssertSpec(MinimumLengthSpecification::class, code: 'NAME_OK')]
    public function getName(): string
    {
        return 'Alan Turing';
    }
}

class ThrowingValidateRuleGetterDto
{
    #[ValidateRule('>', 0, code: 'TOTAL_ERR', message: 'Total must be positive')]
    public function total(): int
    {
        throw new \LogicException('ledger not loaded');
    }
}

class ThrowingGetterWithEmptyMessageDto
{
    #[ValidateRule('notNull', code: 'EMPTY_MSG')]
    public function value(): ?string
    {
        throw new \DomainException();
    }
}

class HealthyGettersDto
{
    #[AssertSpec(IsAdultSpecification::class, code: 'AGE_OK')]
    public function getAge(): int
    {
        return 41;
    }

    #[ValidateRule('>', 100, code: 'TOTAL_LOW', message: 'Total too low')]
    public function total(): int
    {
        return 50;
    }
}

/**
 * Module15_AttributesTest - Unit test suite for PHP 8.4 Declarative Attributes
 *
 * Validates #[AssertSpec], #[ValidateRule], AttributeValidator, Spec::validateAttributes,
 * and exception triggers across class, property, and method targets.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification\Tests
 * @subpackage Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module15_AttributesTest extends TestCase
{
    public function run(): void
    {
        $this->testValidDtoPassesAllAttributeRules();
        $this->testFieldLevelAssertSpecFailure();
        $this->testFieldLevelValidateRuleFailures();
        $this->testMultipleRepeatableAttributesOnSameProperty();
        $this->testClassLevelAggregateSpecification();
        $this->testMethodLevelAttributeValidation();
        $this->testAssertAttributesThrowsExceptionOnFailure();
        $this->testFacadeSpecValidateAndAssertIntegration();
        $this->testValidateRuleOperators();
        $this->testUnknownOperatorIsRejectedLoudly();
        $this->testEveryCatalogOperatorIsAccepted();
        $this->testReadmeExample11RunsAsWritten();
        $this->testNotBlankOperator();
        $this->testValueIsAnAliasOfExpected();
        $this->testUnknownSpecificationClassIsRejectedLoudly();
        $this->testKnownSpecificationClassKeepsResolvingAndCaching();
        $this->testAssertSpecEvaluationErrorBecomesErrorResult();
        $this->testPlainAttributeViolationsAreNotErrors();
        $this->testThrowingAnnotatedGetterBecomesErrorResult();
        $this->testHealthyAnnotatedGettersAreUnchanged();
    }

    /**
     * Reproduction (BUG #30): an annotated getter that throws when invoked (before any evaluation)
     * must become an error result of the Notification Pattern, exactly like a specification that
     * throws while being evaluated (BUG #25): never a raw exception escaping validateAttributes().
     */
    private function testThrowingAnnotatedGetterBecomesErrorResult(): void
    {
        // 1. #[AssertSpec] on a getter that throws: before the fix, RuntimeException('boom') escaped here
        $result = Spec::validateAttributes(new ThrowingAssertSpecGetterDto());
        $this->assertFalse($result->isSatisfied);
        $this->assertTrue($result->isError, 'A getter that throws is an evaluation error, as in RN-03 (bug #25)');
        $this->assertInstanceOf(\RuntimeException::class, $result->exception);
        $this->assertEquals('boom', $result->exception->getMessage());

        $failures = $result->getFailuresForProperty('getAge');
        $this->assertCount(1, $failures);
        $this->assertEquals('boom', $failures[0]->message, 'The error failure carries the exception message, not the business message');
        $this->assertEquals('AGE_ERR', $failures[0]->code, 'The attribute code is kept');
        $this->assertEquals(IsAdultSpecification::class, $failures[0]->ruleName);
        $this->assertEquals('getAge', $failures[0]->property);
        $this->assertEquals(\RuntimeException::class, $failures[0]->metadata['evaluation_error']);
        $this->assertEquals('ERROR', $failures[0]->metadata['severity']);
        $this->assertCount(1, $result, 'The healthy getter on the same object is still evaluated and satisfied');

        $e = $this->assertThrows(
            AttributeValidationException::class,
            fn() => Spec::assertAttributes(new ThrowingAssertSpecGetterDto()),
            'assertAttributes must wrap the getter exception, never let it escape'
        );
        $this->assertTrue($e->getResult()->isError);
        $this->assertTrue($e->getPrevious() === $e->getResult()->exception, 'The cause is chained');
        $this->assertEquals('boom', $e->getPrevious()->getMessage());

        // 2. #[ValidateRule] on a getter that throws: same rule, ruleName = ValidateRule(<operator>)
        $result = Spec::validateAttributes(new ThrowingValidateRuleGetterDto());
        $this->assertFalse($result->isSatisfied);
        $this->assertTrue($result->isError);
        $this->assertInstanceOf(\LogicException::class, $result->exception);

        $failures = $result->getFailuresForProperty('total');
        $this->assertCount(1, $failures);
        $this->assertEquals('ledger not loaded', $failures[0]->message);
        $this->assertEquals('TOTAL_ERR', $failures[0]->code);
        $this->assertEquals('ValidateRule(>)', $failures[0]->ruleName);
        $this->assertEquals('total', $failures[0]->property);
        $this->assertEquals(\LogicException::class, $failures[0]->metadata['evaluation_error']);

        $e = $this->assertThrows(
            AttributeValidationException::class,
            fn() => Spec::assertAttributes(new ThrowingValidateRuleGetterDto())
        );
        $this->assertInstanceOf(\LogicException::class, $e->getPrevious());

        // 3. Exception without message: the failure message falls back to the exception class
        $result = Spec::validateAttributes(new ThrowingGetterWithEmptyMessageDto());
        $this->assertTrue($result->isError);
        $this->assertEquals(\DomainException::class, $result->getFailuresForProperty('value')[0]->message);
        $this->assertEquals('EMPTY_MSG', $result->getFailuresForProperty('value')[0]->code);
    }

    /**
     * Regression (BUG #30): getters that return normally keep exactly the same behavior: a satisfied
     * getter adds no failure, a plain violation on a getter is not an error (no isError, no exception,
     * no evaluation_error metadata), and the business message is kept.
     */
    private function testHealthyAnnotatedGettersAreUnchanged(): void
    {
        $result = Spec::validateAttributes(new HealthyGettersDto());
        $this->assertFalse($result->isSatisfied);
        $this->assertFalse($result->isError, 'A plain violation on a getter is not an error');
        $this->assertTrue($result->exception === null);
        $this->assertCount(0, $result->getFailuresForProperty('getAge'), 'The satisfied getter adds no failure');

        $failures = $result->getFailuresForProperty('total');
        $this->assertCount(1, $failures);
        $this->assertEquals('Total too low', $failures[0]->message, 'The business message is kept on a plain violation');
        $this->assertEquals('TOTAL_LOW', $failures[0]->code);
        $this->assertEquals('ValidateRule(>)', $failures[0]->ruleName);
        $this->assertFalse(array_key_exists('evaluation_error', $failures[0]->metadata));
        $this->assertEquals(50, $failures[0]->metadata['actual']);

        $e = $this->assertThrows(AttributeValidationException::class, fn() => Spec::assertAttributes(new HealthyGettersDto()));
        $this->assertTrue($e->getPrevious() === null, 'No cause is chained on a plain violation');

        // A fully valid object with getters is satisfied without error, as before
        $valid = Spec::validateAttributes(new CustomerRegistrationDto('Grace Hopper', 85, 'grace@navy.mil'));
        $this->assertTrue($valid->isSatisfied);
        $this->assertFalse($valid->isError);
    }

    /**
     * Reproduction (BUG #24): a #[AssertSpec] naming a class that does not exist, or that is not a
     * specification, is a configuration error and must raise UnknownSpecificationClassException,
     * never be skipped in silence (which turned the invariant off without any notice).
     */
    private function testUnknownSpecificationClassIsRejectedLoudly(): void
    {
        // 1. Property target with a typo in the class name: before the fix, satisfied with 0 failures
        $typoOnProperty = new class {
            #[AssertSpec('App\Specs\IsAdultSpecificaton', code: 'CUST_AGE', message: 'Customer must be an adult')]
            public int $age = 5;
        };
        $e = $this->assertThrows(
            UnknownSpecificationClassException::class,
            fn() => Spec::validateAttributes($typoOnProperty),
            'validateAttributes must refuse a specification class that does not exist'
        );
        $this->assertEquals('App\Specs\IsAdultSpecificaton', $e->specificationClass);
        $this->assertTrue(str_contains($e->getMessage(), "'App\Specs\IsAdultSpecificaton'"), 'Message names the class');
        $this->assertTrue(str_contains($e->getMessage(), "property 'age'"), 'Message names the annotated property');
        $this->assertTrue(str_contains($e->getMessage(), 'does not exist'), 'Message states the reason');

        // 2. Class target through assert(): the configuration error wins over AttributeValidationException
        $e = $this->assertThrows(
            UnknownSpecificationClassException::class,
            fn() => Spec::assertAttributes(new UnknownSpecOnClassDto()),
            'assertAttributes must raise UnknownSpecificationClassException, not AttributeValidationException'
        );
        $this->assertEquals('App\Specs\DoesNotExist', $e->specificationClass);
        $this->assertTrue(str_contains($e->getMessage(), 'class ' . UnknownSpecOnClassDto::class), 'Message names the annotated class');

        // 3. Getter target
        $e = $this->assertThrows(
            UnknownSpecificationClassException::class,
            fn() => Spec::validateAttributes(new UnknownSpecOnGetterDto()),
            'validateAttributes must refuse an unknown class on a getter'
        );
        $this->assertTrue(str_contains($e->getMessage(), "method 'getScore()'"), 'Message names the annotated method');

        // 4. The class exists but is not a specification: same typed error (was an untyped TypeError)
        $notASpec = new class {
            #[AssertSpec(NotASpecificationClass::class, code: 'X')]
            public int $v = 1;
        };
        $e = $this->assertThrows(
            UnknownSpecificationClassException::class,
            fn() => Spec::validateAttributes($notASpec),
            'A class that does not implement ISpecification must be refused with the same typed error'
        );
        $this->assertEquals(NotASpecificationClass::class, $e->specificationClass);
        $this->assertTrue(str_contains($e->getMessage(), 'does not implement'), 'Message states the reason');
        $this->assertInstanceOf(\InvalidArgumentException::class, $e, 'Configuration errors are InvalidArgumentException, like UnknownRuleOperatorException');
    }

    /**
     * Regression (BUG #24): an existing specification class keeps being resolved, instantiated once
     * (parameterless) and reused from the cache; parameterized ones are instantiated per use; a
     * violation of a known specification is still a plain failure.
     */
    private function testKnownSpecificationClassKeepsResolvingAndCaching(): void
    {
        AttributeValidator::clearCache();
        $cacheProperty = new \ReflectionProperty(AttributeValidator::class, 'instanceCache');

        $dto = new CustomerRegistrationDto('Alan Turing', 41, 'alan@bletchley.uk');
        $this->assertTrue(Spec::validateAttributes($dto)->isSatisfied, 'Known specification classes still validate');

        $cache = $cacheProperty->getValue();
        $this->assertTrue(isset($cache[IsAdultSpecification::class]), 'Parameterless specification is cached after the first resolution');
        $this->assertFalse(isset($cache[MinimumLengthSpecification::class]), 'Parameterized specification is never cached');
        $first = $cache[IsAdultSpecification::class];

        Spec::validateAttributes($dto);
        $cache = $cacheProperty->getValue();
        $this->assertTrue($first === $cache[IsAdultSpecification::class], 'Cached instance is reused on the next validation');

        $young = new CustomerRegistrationDto('Ada Lovelace', 15, 'ada@example.com');
        $result = Spec::validateAttributes($young);
        $this->assertTrue($result->hasError('CUST_AGE'), 'A known specification that is not satisfied still reports its failure');
        $this->assertFalse($result->isError, 'A plain violation is not an evaluation error');
    }

    /**
     * Reproduction (BUG #25): an exception thrown while evaluating a #[AssertSpec] specification is an
     * evaluation error of the Notification Pattern (isError, exception), aggregated into the result,
     * never a raw exception escaping validateAttributes(); assertAttributes() wraps it.
     */
    private function testAssertSpecEvaluationErrorBecomesErrorResult(): void
    {
        $dto = new EvaluationErrorDto();

        // Before the fix: RuntimeException('tariff service unavailable') escaped here
        $result = Spec::validateAttributes($dto);
        $this->assertFalse($result->isSatisfied);
        $this->assertTrue($result->isError, 'An evaluation exception is an error result, as in evaluate() and in the rule engine');
        $this->assertInstanceOf(\RuntimeException::class, $result->exception);
        $this->assertEquals('tariff service unavailable', $result->exception->getMessage());

        $errorFailures = $result->getFailuresForProperty('tariff');
        $this->assertCount(1, $errorFailures);
        $this->assertEquals('TARIFF', $errorFailures[0]->code, 'The attribute code is kept on the error failure');
        $this->assertEquals(UnavailableServiceSpec::class, $errorFailures[0]->ruleName);
        $this->assertEquals('tariff service unavailable', $errorFailures[0]->message, 'The error failure carries the exception message, not the business message');
        $this->assertEquals(\RuntimeException::class, $errorFailures[0]->metadata['evaluation_error']);

        // The plain violation on the same object is still reported, unchanged
        $this->assertTrue($result->hasError('CUST_AGE'));
        $this->assertEquals('Customer must be an adult', $result->getFailuresForProperty('age')[0]->message);
        $this->assertCount(2, $result);

        // Class-level and getter-level targets follow the same rule
        $onClass = Spec::validateAttributes(new EvaluationErrorOnClassDto());
        $this->assertTrue($onClass->isError);
        $this->assertTrue($onClass->hasError('AGG_ERR'));
        $onGetter = Spec::validateAttributes(new EvaluationErrorOnGetterDto());
        $this->assertTrue($onGetter->isError);
        $this->assertCount(1, $onGetter->getFailuresForProperty('getScore'));

        // assertAttributes(): AttributeValidationException carrying the error result and the cause
        $e = $this->assertThrows(
            AttributeValidationException::class,
            fn() => Spec::assertAttributes($dto),
            'assertAttributes must wrap the evaluation error, never let the raw exception escape'
        );
        $this->assertTrue($e->getResult()->isError);
        $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
    }

    /**
     * Regression (BUG #25): plain violations keep exactly the same shape (no error state, no exception,
     * no evaluation_error metadata) and a valid object is satisfied without error.
     */
    private function testPlainAttributeViolationsAreNotErrors(): void
    {
        $invalid = new CustomerRegistrationDto(name: 'Al', age: 15, email: 'fail', creditLimit: 60000.0, tier: 'GOLD', cnpj: 'x');
        $result = Spec::validateAttributes($invalid);
        $this->assertFalse($result->isSatisfied);
        $this->assertFalse($result->isError, 'Violations of the candidate are never an error');
        $this->assertTrue($result->exception === null);
        foreach ($result->failures as $failure) {
            $this->assertFalse(isset($failure->metadata['evaluation_error']), 'No evaluation_error metadata on a plain violation');
        }
        foreach (['AGG_001', 'NAME_LEN', 'CUST_AGE', 'CUST_EMAIL', 'TIER_INVALID', 'TAX_ID'] as $code) {
            $this->assertTrue($result->hasError($code), "Code {$code} still reported");
        }

        $valid = new CustomerRegistrationDto('Grace Hopper', 85, 'grace@navy.mil');
        $ok = Spec::validateAttributes($valid);
        $this->assertTrue($ok->isSatisfied);
        $this->assertFalse($ok->isError);

        $e = $this->assertThrows(AttributeValidationException::class, fn() => Spec::assertAttributes($invalid));
        $this->assertFalse($e->getResult()->isError);
        $this->assertTrue($e->getPrevious() === null, 'No cause attached when there is no evaluation error');
    }

    /**
     * Reproduction (BUG #18): a #[ValidateRule] whose operator is not in the catalog must raise
     * UnknownRuleOperatorException, never a silent business violation.
     */
    private function testUnknownOperatorIsRejectedLoudly(): void
    {
        // 1. Construction: the attribute itself refuses the operator
        // ('not_blank' was the original example here; it joined the catalog in Forward 014, so the typo moved)
        $e = $this->assertThrows(
            UnknownRuleOperatorException::class,
            fn() => new ValidateRule('is_blank', message: 'Name cannot be empty'),
            'ValidateRule must refuse an operator outside the catalog'
        );
        $this->assertEquals('is_blank', $e->operator);
        $this->assertTrue(str_contains($e->getMessage(), "'is_blank'"), 'Message must name the offending operator');
        $this->assertTrue(str_contains($e->getMessage(), 'not_blank'), 'Message lists the catalog, not_blank included');
        $this->assertTrue(str_contains($e->getMessage(), 'not_empty'), 'Message must list the accepted operators');

        // 2. Property target through validate(): error, not a failure with the business message
        $typoOnProperty = new class {
            #[ValidateRule('not_blnak', message: 'Name cannot be empty', code: 'NAME_REQ')]
            public string $name = 'Alice Smith';
        };
        $this->assertThrows(
            UnknownRuleOperatorException::class,
            fn() => Spec::validateAttributes($typoOnProperty),
            'validateAttributes must raise on an unknown operator instead of reporting NAME_REQ'
        );

        // 3. Getter target through assert(): the typed configuration error wins over AttributeValidationException
        $typoOnGetter = new class {
            #[ValidateRule('>>', expected: 0, code: 'TOTAL_SCORE')]
            public function getScore(): int
            {
                return 10;
            }
        };
        $this->assertThrows(
            UnknownRuleOperatorException::class,
            fn() => Spec::assertAttributes($typoOnGetter),
            'assertAttributes must raise UnknownRuleOperatorException, not AttributeValidationException'
        );
    }

    /**
     * Regression (BUG #18): every operator of the public catalog, in any letter case and with
     * surrounding whitespace, keeps being accepted by the attribute and evaluated by the validator.
     */
    private function testEveryCatalogOperatorIsAccepted(): void
    {
        $this->assertTrue(count(ValidateRule::OPERATORS) >= 22, 'Catalog must expose the full operator list');

        foreach (ValidateRule::OPERATORS as $operator) {
            $rule = new ValidateRule($operator, expected: [1, 2]);
            $this->assertEquals($operator, $rule->operator);
        }

        // Aliases and normalization (case + whitespace) survive
        foreach ([' NotIn ', 'NOT_IN', 'notNull', 'Not_Empty', 'EMAIL', ' <> '] as $spelled) {
            $rule = new ValidateRule($spelled, expected: []);
            $this->assertEquals($spelled, $rule->operator, 'Operator spelling is preserved on the attribute');
        }

        $aliases = new class {
            #[ValidateRule(operator: 'not_in', expected: ['BANNED'], code: 'ROLE_NOT_IN')]
            public string $role = 'USER';

            #[ValidateRule(operator: ' NOT_NULL ', code: 'STATUS_NOT_NULL')]
            public ?string $status = 'ACTIVE';

            #[ValidateRule(operator: 'not_empty', code: 'NAME_NOT_EMPTY')]
            public string $name = 'Alice';

            #[ValidateRule(operator: '<>', expected: 'X', code: 'NOT_X')]
            public string $letter = 'Y';
        };
        $this->assertTrue(Spec::validateAttributes($aliases)->isSatisfied, 'Aliases with underscore, case and whitespace keep working');
    }

    /**
     * Forward 014 (R4 + R5): README example 11 ("Declarative Attributes Engine"), transcribed as written.
     * CustomerMustBeActiveSpec is not defined by the README; here it is an always-satisfied spec.
     */
    private function testReadmeExample11RunsAsWritten(): void
    {
        $dto = new Readme11RegisterCustomerDto('Alice Smith', 16, 'alice@example.com');

        // 1. Non-throwing Notification Pattern validation
        $result = Spec::validateAttributes($dto);
        $this->assertFalse($result->isSatisfied, 'README ex. 11: a 16-year-old must be rejected');
        $this->assertCount(1, $result->failures, 'README ex. 11: exactly one violation (UNDERAGE); not_blank and email pass');
        $failure = $result->failures[0];
        $this->assertEquals('UNDERAGE', $failure->code);
        $this->assertEquals('Customer must be at least 18 years old', $failure->message);
        $this->assertEquals('ValidateRule(>=)', $failure->ruleName);
        $this->assertEquals('age', $failure->property);
        $this->assertEquals(18, $failure->metadata['expected'], "value: 18 must reach the validator as 'expected'");

        // 2. Strict throwing assertion
        $e = $this->assertThrows(
            AttributeValidationException::class,
            fn() => Spec::assertAttributes($dto),
            'README ex. 11: assertAttributes must throw for the underage DTO'
        );
        $this->assertCount(1, $e->getResult()->failures);

        // Adult with the same attributes passes every rule, including not_blank and email
        $adult = new Readme11RegisterCustomerDto('Bob Stone', 40, 'bob@example.com');
        $this->assertTrue(Spec::validateAttributes($adult)->isSatisfied);
        Spec::assertAttributes($adult);
    }

    /**
     * Forward 014 (R4, RN-05): 'not_blank' / 'notblank' semantics.
     */
    private function testNotBlankOperator(): void
    {
        $this->assertTrue(in_array('not_blank', ValidateRule::OPERATORS, true), 'not_blank is in the catalog');
        $this->assertTrue(in_array('notblank', ValidateRule::OPERATORS, true), 'notblank alias is in the catalog');

        $cases = [
            // value, expected verdict, label
            ['Alice Smith', true, 'non-empty string'],
            ['  x ', true, 'string with content around whitespace'],
            ['', false, 'empty string'],
            ['   ', false, 'whitespace-only string'],
            ["\t\n", false, 'tabs and newlines only'],
            [null, false, 'null'],
            [false, false, 'false'],
            [true, true, 'true'],
            [0, true, 'integer zero is present (unlike not_empty)'],
            [0.0, true, 'float zero is present'],
            [42, true, 'integer'],
            [[], false, 'empty array'],
            [[1], true, 'non-empty array'],
            [new \stdClass(), true, 'object'],
        ];

        foreach ($cases as [$value, $expectedVerdict, $label]) {
            $holder = new Readme11NotBlankHolder($value);
            $result = Spec::validateAttributes($holder);
            $this->assertEquals($expectedVerdict, $result->isSatisfied, "not_blank on {$label}");
            if (!$expectedVerdict) {
                $this->assertTrue($result->hasError('BLANK'), "not_blank failure on {$label} carries its code");
            }
        }

        // Alias spelling and normalization
        $alias = new class {
            #[ValidateRule(' NotBlank ', code: 'ALIAS')]
            public string $name = 'ok';
        };
        $this->assertTrue(Spec::validateAttributes($alias)->isSatisfied, 'notblank alias with case/whitespace');
    }

    /**
     * Forward 014 (R5, RN-06): value: is an alias of expected:.
     */
    private function testValueIsAnAliasOfExpected(): void
    {
        $this->assertEquals(18, (new ValidateRule('>=', value: 18))->expected, 'value: alone feeds expected');
        $this->assertEquals(18, (new ValidateRule('>=', expected: 18))->expected, 'expected: alone is unchanged');
        $this->assertEquals(18, (new ValidateRule('>=', expected: 18, value: 18))->expected, 'both equal is accepted');
        $this->assertEquals(18, (new ValidateRule('>=', 18))->expected, 'positional expected is unchanged');
        $this->assertTrue((new ValidateRule('notnull'))->expected === null, 'neither given: expected stays null');
        $this->assertEquals([1, 5], (new ValidateRule('between', value: [1, 5]))->expected, 'value: carries arrays too');

        $e = $this->assertThrows(
            \InvalidArgumentException::class,
            fn() => new ValidateRule('>=', expected: 18, value: 21),
            'expected and value with different contents must be refused'
        );
        $this->assertTrue(str_contains($e->getMessage(), "'value'"), 'Message names the alias');

        // Through reflection, on a property and on a getter
        $holder = new class {
            #[ValidateRule('in', value: ['A', 'B'], code: 'IN_VALUE')]
            public string $letter = 'C';

            #[ValidateRule('<', value: 10, code: 'LT_VALUE')]
            public function getScore(): int
            {
                return 50;
            }
        };
        $result = Spec::validateAttributes($holder);
        $this->assertFalse($result->isSatisfied);
        $this->assertTrue($result->hasError('IN_VALUE'));
        $this->assertTrue($result->hasError('LT_VALUE'));
        $this->assertEquals(['A', 'B'], $result->getFailuresForProperty('letter')[0]->metadata['expected']);
    }

    private function testValidDtoPassesAllAttributeRules(): void
    {
        $dto = new CustomerRegistrationDto(
            name: 'Alice Santos',
            age: 28,
            email: 'alice@antevemus.com.br',
            creditLimit: 5000.0,
            tier: 'PREMIUM',
            cnpj: '12.345.678/0001-90'
        );

        $result = AttributeValidator::validate($dto);

        $this->assertTrue($result->isSatisfied, 'Valid DTO should satisfy all declarative specifications');
        $this->assertCount(0, $result->failures, 'Valid DTO must produce zero failures');
    }

    private function testFieldLevelAssertSpecFailure(): void
    {
        // Age is under 18 -> fails IsAdultSpecification
        $dto = new CustomerRegistrationDto(
            name: 'Bob Underage',
            age: 16,
            email: 'bob@example.com'
        );

        $result = AttributeValidator::validate($dto);

        $this->assertFalse($result->isSatisfied, 'Underage customer should fail IsAdultSpecification');
        $this->assertTrue($result->hasError('CUST_AGE'), 'Should report CUST_AGE failure code');

        $ageFailures = $result->getFailuresForProperty('age');
        $this->assertCount(2, $ageFailures, 'Should have failures from AssertSpec and between rule');
        $this->assertEquals('Customer must be an adult', $ageFailures[0]->message);
    }

    private function testFieldLevelValidateRuleFailures(): void
    {
        // Invalid email format and invalid tier
        $dto = new CustomerRegistrationDto(
            name: 'Charlie',
            age: 25,
            email: 'invalid-email-address',
            creditLimit: -50.0,
            tier: 'UNAUTHORIZED_TIER'
        );

        $result = AttributeValidator::validate($dto);

        $this->assertFalse($result->isSatisfied);
        $this->assertTrue($result->hasError('CUST_EMAIL'));
        $this->assertTrue($result->hasError('CREDIT_LIMIT'));
        $this->assertTrue($result->hasError('TIER_INVALID'));
    }

    private function testMultipleRepeatableAttributesOnSameProperty(): void
    {
        // Name with 2 characters (fails MinimumLengthSpecification min=3)
        $dto = new CustomerRegistrationDto(
            name: 'Al',
            age: 30,
            email: 'al@example.com'
        );

        $result = AttributeValidator::validate($dto);

        $this->assertFalse($result->isSatisfied);
        $nameFailures = $result->getFailuresForProperty('name');
        $this->assertCount(1, $nameFailures);
        $this->assertEquals('NAME_LEN', $nameFailures[0]->code);
    }

    private function testClassLevelAggregateSpecification(): void
    {
        // Exceeds aggregate limit of 50,000 -> ValidCustomerAggregateSpec fails
        $dto = new CustomerRegistrationDto(
            name: 'Diana Prince',
            age: 35,
            email: 'diana@example.com',
            creditLimit: 90000.0
        );

        $result = AttributeValidator::validate($dto);

        $this->assertFalse($result->isSatisfied);
        $this->assertTrue($result->hasError('AGG_001'));
    }

    private function testMethodLevelAttributeValidation(): void
    {
        $dto = new CustomerRegistrationDto(
            name: 'Evan',
            age: 20,
            email: 'evan@example.com'
        );

        $result = AttributeValidator::validate($dto);
        $this->assertTrue($result->isSatisfied);
    }

    private function testAssertAttributesThrowsExceptionOnFailure(): void
    {
        $invalidDto = new CustomerRegistrationDto(
            name: 'F',
            age: 10,
            email: 'not-an-email'
        );

        $this->assertThrows(
            AttributeValidationException::class,
            function () use ($invalidDto) {
                AttributeValidator::assert($invalidDto);
            },
            'AttributeValidator::assert should throw AttributeValidationException on invalid entity'
        );
    }

    private function testFacadeSpecValidateAndAssertIntegration(): void
    {
        $validDto = new CustomerRegistrationDto(
            name: 'Grace Hopper',
            age: 40,
            email: 'grace@navy.mil'
        );

        $result = Spec::validateAttributes($validDto);
        $this->assertTrue($result->isSatisfied);

        // Assert should not throw for valid DTO
        Spec::assertAttributes($validDto);

        $invalidDto = new CustomerRegistrationDto(
            name: '',
            age: 15,
            email: 'fail'
        );

        $invalidResult = Spec::validateAttributes($invalidDto);
        $this->assertFalse($invalidResult->isSatisfied);

        $this->assertThrows(
            AttributeValidationException::class,
            function () use ($invalidDto) {
                Spec::assertAttributes($invalidDto);
            }
        );
    }

    private function testValidateRuleOperators(): void
    {
        $dummy = new class {
            #[ValidateRule(operator: 'regex', expected: '/^[A-Z]{3}-\d{4}$/', code: 'LICENSE_PLATE')]
            public string $plate = 'ABC-1234';

            #[ValidateRule(operator: 'notNull', code: 'STATUS_NOT_NULL')]
            public ?string $status = 'ACTIVE';

            #[ValidateRule(operator: 'notIn', expected: ['BANNED', 'DELETED'], code: 'STATUS_ALLOWED')]
            public string $role = 'USER';
        };

        $result = Spec::validateAttributes($dummy);
        $this->assertTrue($result->isSatisfied, 'Dummy object with regex, notNull, notIn should satisfy rules');

        $invalidDummy = new class {
            #[ValidateRule(operator: 'regex', expected: '/^[A-Z]{3}-\d{4}$/', code: 'LICENSE_PLATE')]
            public string $plate = '123-INVALID';

            #[ValidateRule(operator: 'notNull', code: 'STATUS_NOT_NULL')]
            public ?string $status = null;

            #[ValidateRule(operator: 'notIn', expected: ['BANNED', 'DELETED'], code: 'STATUS_ALLOWED')]
            public string $role = 'BANNED';
        };

        $failedResult = Spec::validateAttributes($invalidDummy);
        $this->assertFalse($failedResult->isSatisfied);
        $this->assertTrue($failedResult->hasError('LICENSE_PLATE'));
        $this->assertTrue($failedResult->hasError('STATUS_NOT_NULL'));
        $this->assertTrue($failedResult->hasError('STATUS_ALLOWED'));
    }
}
