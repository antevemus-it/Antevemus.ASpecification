<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests;

use Closure;
use Countable;
use Throwable;
use AssertionError;

abstract class TestCase
{
    private int $assertionCount = 0;

    abstract public function run(): void;

    public function getAssertionCount(): int
    {
        return $this->assertionCount;
    }

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertionCount++;
        if (!$condition) {
            throw new AssertionError($message ?: 'Expected true but received false');
        }
    }

    protected function assertFalse(bool $condition, string $message = ''): void
    {
        $this->assertionCount++;
        if ($condition) {
            throw new AssertionError($message ?: 'Expected false but received true');
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertionCount++;
        if ($expected !== $actual) {
            $msg = $message ?: sprintf('Expected %s but received %s', var_export($expected, true), var_export($actual, true));
            throw new AssertionError($msg);
        }
    }

    protected function assertCount(int $expectedCount, Countable|array $haystack, string $message = ''): void
    {
        $this->assertionCount++;
        $actual = count($haystack);
        if ($actual !== $expectedCount) {
            throw new AssertionError($message ?: "Expected count {$expectedCount} but got {$actual}");
        }
    }

    protected function assertThrows(string $expectedClass, Closure $fn, string $message = ''): Throwable
    {
        $this->assertionCount++;
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $expectedClass) {
                throw new AssertionError(sprintf('Expected %s but %s was thrown', $expectedClass, get_class($e)));
            }
            return $e;
        }
        throw new AssertionError($message ?: "Expected exception {$expectedClass} was not thrown");
    }
    protected function assertInstanceOf(string $expectedClass, object $actual, string $message = ''): void
    {
        $this->assertionCount++;
        if (!$actual instanceof $expectedClass) {
            $msg = $message ?: sprintf('Expected instance of %s but got %s', $expectedClass, get_class($actual));
            throw new AssertionError($msg);
        }
    }
}
