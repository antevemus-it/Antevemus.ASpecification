<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Contracts\Concurrent\ISynchronizer;
use Antevemus\ASpecification\Concurrent\NullSynchronizer;
use Antevemus\ASpecification\Concurrent\FileLockSynchronizer;
use Antevemus\ASpecification\Concurrent\SemaphoreSynchronizer;
use Antevemus\ASpecification\Concurrent\SysVSemaphoreSynchronizer;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Tests\Support\LazyProbeEntity;
use Antevemus\ASpecification\Tests\Support\SysVCrossProcessProbe;
use Fiber;
use InvalidArgumentException;
use RuntimeException;

class Module6_ConcurrencyTest extends TestCase
{
    public function run(): void
    {
        $this->testNullSynchronizer();
        $this->testFileLockSynchronizer();
        $this->testSemaphoreSynchronizer();

        // Forward 017 (v1.5.0), RN-02: SysVSemaphoreSynchronizer, read/write lock between processes.
        $this->testSysVSemaphoreSynchronizerAcrossProcesses();
    }

    private function testNullSynchronizer(): void
    {
        $sync = new NullSynchronizer();
        $this->assertInstanceOf(ISynchronizer::class, $sync);

        // callConcurrently
        $resCon = $sync->callConcurrently(fn(): string => 'concurrent_ok');
        $this->assertEquals('concurrent_ok', $resCon);

        // runConcurrently
        $ranCon = false;
        $sync->runConcurrently(function () use (&$ranCon): void {
            $ranCon = true;
        });
        $this->assertTrue($ranCon);

        // callExclusively
        $resExc = $sync->callExclusively(fn(): int => 42);
        $this->assertEquals(42, $resExc);

        // runExclusively
        $ranExc = false;
        $sync->runExclusively(function () use (&$ranExc): void {
            $ranExc = true;
        });
        $this->assertTrue($ranExc);
    }

    private function testFileLockSynchronizer(): void
    {
        $tempLock = tempnam(sys_get_temp_dir(), 'test_flock_');
        $sync = new FileLockSynchronizer($tempLock);
        $this->assertInstanceOf(ISynchronizer::class, $sync);
        $this->assertEquals($tempLock, $sync->getLockFilePath());

        // Concorrência e exclusividade
        $val1 = $sync->callConcurrently(fn(): string => 'data_read');
        $this->assertEquals('data_read', $val1);

        $written = false;
        $sync->runExclusively(function () use (&$written): void {
            $written = true;
        });
        $this->assertTrue($written);

        $val2 = $sync->callExclusively(fn(): int => 100);
        $this->assertEquals(100, $val2);

        // Reentrância: chamada concorrente dentro de chamada exclusiva
        $nestedOk = $sync->callExclusively(function () use ($sync): bool {
            return $sync->callConcurrently(fn(): bool => true);
        });
        $this->assertTrue($nestedOk);

        // Garantia de liberação em caso de exceção no finally
        $threw = false;
        try {
            $sync->callExclusively(function (): never {
                throw new RuntimeException("Simulated error inside lock");
            });
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertEquals("Simulated error inside lock", $e->getMessage());
        }
        $this->assertTrue($threw);

        // Após exceção, o lock deve estar livre e utilizável
        $valPost = $sync->callConcurrently(fn(): string => 'recovered');
        $this->assertEquals('recovered', $valPost);

        if (file_exists($tempLock)) {
            @unlink($tempLock);
        }
    }

    private function testSemaphoreSynchronizer(): void
    {
        $sync = new SemaphoreSynchronizer(500);
        $this->assertInstanceOf(ISynchronizer::class, $sync);
        $this->assertEquals(500, $sync->getMaxPermits());
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->isExclusiveLocked());

        // Consumo de permissão concorrente durante execução
        $permitsObservedDuringCall = null;
        $res = $sync->callConcurrently(function () use ($sync, &$permitsObservedDuringCall): string {
            $permitsObservedDuringCall = $sync->getAvailablePermits();
            $this->assertTrue($sync->hasAcquiredPermit());
            return 'read_done';
        });
        $this->assertEquals('read_done', $res);
        $this->assertEquals(499, $permitsObservedDuringCall);
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->hasAcquiredPermit());

        // Consumo total durante lock exclusivo
        $permitsDuringExclusive = null;
        $exclusiveLockedDuringCall = null;
        $sync->runExclusively(function () use ($sync, &$permitsDuringExclusive, &$exclusiveLockedDuringCall): void {
            $permitsDuringExclusive = $sync->getAvailablePermits();
            $exclusiveLockedDuringCall = $sync->isExclusiveLocked();
            $this->assertTrue($sync->hasAcquiredPermit());
        });
        $this->assertEquals(0, $permitsDuringExclusive);
        $this->assertTrue($exclusiveLockedDuringCall);
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->isExclusiveLocked());

        // Reentrância em SemaphoreSynchronizer
        $reentrantOk = $sync->callExclusively(function () use ($sync): bool {
            return $sync->callConcurrently(function () use ($sync): bool {
                return $sync->callExclusively(fn(): bool => true);
            });
        });
        $this->assertTrue($reentrantOk);
        $this->assertEquals(500, $sync->getAvailablePermits());

        // Liberação garantida em exceção
        $threw = false;
        try {
            $sync->callExclusively(function (): never {
                throw new RuntimeException("Crash inside semaphore");
            });
        } catch (RuntimeException $e) {
            $threw = true;
        }
        $this->assertTrue($threw);
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->isExclusiveLocked());

        // Reentrância em modo concorrente aninhado
        $nestedConcurrent = $sync->callConcurrently(function () use ($sync): string {
            return $sync->callConcurrently(fn(): string => 'nested_concurrent_ok');
        });
        $this->assertEquals('nested_concurrent_ok', $nestedConcurrent);
        $this->assertEquals(500, $sync->getAvailablePermits());

        // Exclusivo dentro de concorrente e reentrante (Domian SemaphoreSynchronizerTest
        // .shouldNotDeadlockWhenInvokingAnExclusiveMethodFromAConcurrentMethod): o mesmo contexto ja
        // detem a permissao, entao o bloco exclusivo roda direto, sem drenar nem lancar.
        // Ate a 1.4.3 esta suite fixava o contrario (RuntimeException), invertendo o Java.
        $promotedResult = null;
        $permitsSeenInsideExclusive = null;
        $sync->callConcurrently(function () use ($sync, &$promotedResult, &$permitsSeenInsideExclusive): void {
            $promotedResult = $sync->callExclusively(function () use ($sync, &$permitsSeenInsideExclusive): int {
                $permitsSeenInsideExclusive = $sync->getAvailablePermits();
                return 123;
            });
        });
        $this->assertEquals(123, $promotedResult);
        $this->assertEquals(499, $permitsSeenInsideExclusive, 'bloco aninhado nao toca nas permissoes');
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->isExclusiveLocked());

        // Outro contexto de execucao (Fiber) nao pode ser esperado num processo unico: a disputa real
        // continua lancando, agora explicando o limite.
        $fiber = new \Fiber(function () use ($sync): void {
            $sync->callConcurrently(function (): void {
                \Fiber::suspend();
            });
        });
        $fiber->start();
        $this->assertEquals(499, $sync->getAvailablePermits(), 'fiber suspensa segura a permissao');
        $crossContext = $this->assertThrows(RuntimeException::class, fn() => $sync->callExclusively(fn(): bool => true));
        $this->assertTrue(str_contains($crossContext->getMessage(), 'Java would wait'));
        $fiber->resume();
        $this->assertEquals(500, $sync->getAvailablePermits());
        $this->assertFalse($sync->hasAcquiredPermit());
    }

    /**
     * RN-02 (forward 017, v1.5.0): SysVSemaphoreSynchronizer.
     *
     * Cenário Gherkin "dois processos respeitam o escritor exclusivo": o pai entra em
     * callExclusively(), o filho (outro processo PHP, pcntl_fork()+pcntl_exec() ou proc_open())
     * pede callConcurrently() no mesmo nome e só executa depois que o pai libera; ninguém fica
     * preso. Controle negativo: com outro nome o filho NÃO espera. Sem ext-sysvsem o teste entre
     * processos é pulado e só o contrato de ausência (RuntimeException explícita) é verificado.
     */
    private function testSysVSemaphoreSynchronizerAcrossProcesses(): void
    {
        if (!SysVSemaphoreSynchronizer::isSupported()) {
            $e = $this->assertThrows(RuntimeException::class, fn() => new SysVSemaphoreSynchronizer('aspec-test', 4));
            $this->assertTrue(str_contains($e->getMessage(), 'SemaphoreSynchronizer'), 'a mensagem manda usar SemaphoreSynchronizer');
            fwrite(STDOUT, "    [AVISO] ext-sysvsem ausente; teste entre processos do SysVSemaphoreSynchronizer pulado (RN-02).\n");
            return;
        }

        $name = 'aspec-test-' . getmypid() . '-' . bin2hex(random_bytes(3));

        // 1. Chaves SysV: estáveis (crc32 do nome), distintas por papel, nunca IPC_PRIVATE (0)
        $this->assertEquals(SysVSemaphoreSynchronizer::keyFor($name, 'read'), SysVSemaphoreSynchronizer::keyFor($name, 'read'));
        $this->assertTrue(SysVSemaphoreSynchronizer::keyFor($name, 'read') !== SysVSemaphoreSynchronizer::keyFor($name, 'write'));
        $this->assertTrue(SysVSemaphoreSynchronizer::keyFor($name, 'read') > 0);

        // 2. Validação do construtor
        $this->assertThrows(InvalidArgumentException::class, fn() => new SysVSemaphoreSynchronizer('', 4));
        $this->assertThrows(InvalidArgumentException::class, fn() => new SysVSemaphoreSynchronizer($name, 0));
        $this->assertThrows(InvalidArgumentException::class, fn() => new SysVSemaphoreSynchronizer($name, SysVSemaphoreSynchronizer::MAX_SYSV_PERMITS + 1));

        // 3. Mesmo processo: retorno de valor, reentrância nos quatro modos, permits devolvidos após exceção
        $sync = new SysVSemaphoreSynchronizer($name, 4);
        try {
            $this->assertEquals($name, $sync->getName());
            $this->assertEquals(4, $sync->getMaxPermits());
            $this->assertEquals('c', $sync->callConcurrently(fn(): string => 'c'));
            $this->assertEquals('x', $sync->callExclusively(fn(): string => 'x'));
            $this->assertEquals('cc', $sync->callConcurrently(fn() => $sync->callConcurrently(fn(): string => 'cc')));
            $this->assertEquals('ce', $sync->callConcurrently(fn() => $sync->callExclusively(fn(): string => 'ce')));
            $this->assertEquals('ec', $sync->callExclusively(fn() => $sync->callConcurrently(fn(): string => 'ec')));
            $this->assertEquals('ee', $sync->callExclusively(fn() => $sync->callExclusively(fn(): string => 'ee')));
            $this->assertTrue($sync->callExclusively(fn(): bool => $sync->isExclusiveLocked() && $sync->hasAcquiredPermit()));
            $this->assertFalse($sync->isExclusiveLocked());
            $this->assertFalse($sync->hasAcquiredPermit());

            $this->assertThrows(\LogicException::class, fn() => $sync->callExclusively(function (): void {
                throw new \LogicException('falha no bloco');
            }));
            $this->assertFalse($sync->isExclusiveLocked(), 'o bloco que lança devolve o lock exclusivo');
            $this->assertEquals('again', $sync->callExclusively(fn(): string => 'again'), 'todos os permits voltaram (senão o dreno travaria)');

            // 4. Outro contexto do MESMO processo (Fiber) com permit: o pedido exclusivo esperaria por si mesmo → recusa
            $fiber = new Fiber(function () use ($sync): void {
                $sync->callConcurrently(function (): void {
                    Fiber::suspend();
                });
            });
            $fiber->start();
            $this->assertThrows(RuntimeException::class, fn() => $sync->callExclusively(fn() => null));
            $fiber->resume();
            $this->assertTrue($fiber->isTerminated());
            $this->assertEquals('free', $sync->callExclusively(fn(): string => 'free'));

            // 5. Encaixe nos repositórios (paridade 1.4.4): opção injetável, leituras e escritas passam por ele
            $repo = new InMemoryRepository([], null, $sync);
            $repo->put(new LazyProbeEntity(1));
            $this->assertEquals(1, $repo->count(new AlwaysTrueSpecification()));
        } finally {
            $sync->remove();
        }
        $this->assertThrows(RuntimeException::class, fn() => $sync->callConcurrently(fn() => null), 'removido, não pode mais ser usado');

        // 6. Gherkin: dois processos respeitam o escritor exclusivo (fork+exec quando há pcntl)
        $this->assertCrossProcessTimeline(SysVCrossProcessProbe::run($name . '-a', 4), true);

        // 7. Mesmo cenário com proc_open() (o caminho de quem não tem pcntl)
        $viaProcOpen = SysVCrossProcessProbe::run($name . '-b', 4, true);
        $this->assertEquals('proc_open', $viaProcOpen['mode']);
        $this->assertCrossProcessTimeline($viaProcOpen, true);

        // 8. Controle negativo: o filho usa OUTRO nome, então não espera o pai (o teste discrimina)
        $this->assertCrossProcessTimeline(SysVCrossProcessProbe::run($name . '-c', 4, false, $name . '-other'), false);
    }

    /**
     * @param array{mode: string, events: list<array{event: string, at: int}>, childExit: int|null, timedOut: bool, parentReacquired: bool} $result
     * @param bool $childMustWait Whether "child-ran" must come after "parent-release"
     */
    private function assertCrossProcessTimeline(array $result, bool $childMustWait): void
    {
        $this->assertFalse($result['timedOut'], 'nenhum processo pode ficar preso');
        $this->assertEquals(0, $result['childExit'], 'o filho termina com sucesso: ' . json_encode($result['events']));
        $this->assertTrue($result['parentReacquired'], 'o pai readquire o lock exclusivo depois do filho');

        $at = [];
        foreach ($result['events'] as $entry) {
            $at[$entry['event']] = $entry['at'];
        }
        foreach (['parent-acquired', 'child-trying', 'parent-release', 'child-ran'] as $event) {
            $this->assertTrue(isset($at[$event]), "evento {$event} ausente: " . json_encode($result['events']));
        }
        $this->assertTrue($at['child-trying'] < $at['parent-release'], 'o filho pediu o lock enquanto o pai o segurava');
        if ($childMustWait) {
            $this->assertTrue($at['child-ran'] > $at['parent-release'], 'o filho só executa depois que o pai libera');
        } else {
            $this->assertTrue($at['child-ran'] < $at['parent-release'], 'controle negativo: com outro nome o filho não espera');
        }
    }
}
