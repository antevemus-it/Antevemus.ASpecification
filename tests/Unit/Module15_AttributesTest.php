<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Attributes\AssertSpec;
use Antevemus\ASpecification\Attributes\AttributeValidator;
use Antevemus\ASpecification\Attributes\Exceptions\AttributeValidationException;
use Antevemus\ASpecification\Attributes\Exceptions\UnknownRuleOperatorException;
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
