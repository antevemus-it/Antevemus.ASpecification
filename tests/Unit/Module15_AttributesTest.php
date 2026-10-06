<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Attributes\AssertSpec;
use Antevemus\ASpecification\Attributes\AttributeValidator;
use Antevemus\ASpecification\Attributes\Exceptions\AttributeValidationException;
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
