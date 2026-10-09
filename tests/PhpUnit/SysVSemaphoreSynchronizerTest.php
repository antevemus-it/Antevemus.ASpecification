<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Concurrent\SysVSemaphoreSynchronizer;
use Antevemus\ASpecification\Tests\Support\SysVCrossProcessProbe;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SysVSemaphoreSynchronizerTest - cross-process scenario of RN-02 (forward 017, v1.5.0) as a PHPUnit
 * test, so that a runtime without ext-sysvsem reports it as SKIPPED instead of silently passing.
 *
 * The module suite (Module6_ConcurrencyTest) runs the same scenario through the canonical runner;
 * this class is the PHPUnit-visible face of the extension requirement.
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 */
final class SysVSemaphoreSynchronizerTest extends TestCase
{
    #[RequiresPhpExtension('sysvsem')]
    public function testChildProcessWaitsForTheExclusiveParent(): void
    {
        $result = SysVCrossProcessProbe::run('aspec-test-phpunit-' . getmypid(), 4);

        self::assertFalse($result['timedOut'], 'no process may stay stuck');
        self::assertSame(0, $result['childExit'], json_encode($result['events']) ?: '');
        self::assertTrue($result['parentReacquired']);

        $at = array_column($result['events'], 'at', 'event');
        self::assertArrayHasKey('child-ran', $at);
        self::assertLessThan($at['parent-release'], $at['child-trying'], 'the child asked while the parent held the lock');
        self::assertGreaterThan($at['parent-release'], $at['child-ran'], 'the child ran only after the parent released');
    }

    public function testWithoutTheExtensionTheChoiceIsExplicit(): void
    {
        if (SysVSemaphoreSynchronizer::isSupported()) {
            self::assertInstanceOf(SysVSemaphoreSynchronizer::class, $sync = new SysVSemaphoreSynchronizer('aspec-test-phpunit-ok-' . getmypid(), 2));
            $sync->remove();
            return;
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SemaphoreSynchronizer');
        new SysVSemaphoreSynchronizer('aspec-test', 4);
    }
}
