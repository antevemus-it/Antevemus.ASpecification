<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\GuardExpression as G;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\EffectDispatcher;
use Antevemus\ASpecification\StateMachine\Runner\EffectHandlerRegistry;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\StateMachine\TransitionCandidate;
use DateTimeImmutable;
use PDO;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Module45Answers - The Answers of 2.9.0 That 2.10.0 Must Keep
 *
 * Uses only the API of 2.9.0, so the same code runs on both versions: collect() gathers the array forms
 * of what the pure core and the runner answer over three machines (results of can() and nextEvents(),
 * history, outbox, instances, idempotency, and the rows of a SQLite database created by the 2.9.0 DDL).
 * The digests of the answers of 2.9.0 (digests() over collect(), run once on the 2.9.0 code) are in
 * tests/Fixtures/state_machine/answers_2_9_0.json; Module 45 compares the digests of the current code
 * with them, piece by piece.
 *
 * @version 2.10.0
 */
final class Module45Answers
{
    public const QUOTE_FIXTURE = __DIR__ . '/../Fixtures/state_machine/virttus_quote.v1.json';

    public const DDL_2_9 = __DIR__ . '/../Fixtures/state_machine/state_machine.2.9.ansi.sql';

    /**
     * @return array<string, mixed>
     */
    public static function collect(): array
    {
        $answers = [
            'quote' => self::quoteAnswers(),
            'approval' => self::approvalAnswers(),
            'runner_memory' => self::runnerAnswers(MachinePersistence::inMemory(), null),
        ];
        if (extension_loaded('pdo_sqlite')) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::loadDdl($pdo, (string) file_get_contents(self::DDL_2_9));
            $answers['runner_sqlite_2_9_ddl'] = self::runnerAnswers(MachinePersistence::pdo($pdo), $pdo);
        }

        return $answers;
    }

    /**
     * SHA-256 of the JSON of each piece of collect(): one per walk row of the quote machine, per role and
     * amount of the approval machine, per part of each runner flow.
     *
     * @return array<string, string>
     */
    public static function digests(): array
    {
        $digests = [];
        foreach (self::collect() as $section => $pieces) {
            foreach ($pieces as $name => $piece) {
                $digests[$section . '/' . $name] = hash('sha256', (string) json_encode($piece, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            }
        }

        return $digests;
    }

    /** The approval machine by ranges (several candidates for APPROVE) and its guards. */
    public static function approvalMachine(): MachineDefinition
    {
        return Spec::machine('approval', 1)
            ->region('flow', 'awaiting_approval')
                ->state('awaiting_approval')
                    ->on('APPROVE', 'approved', 'approve_analyst')
                        ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['analyst']]), G::ref('amountUpTo', ['max' => 10000])))
                    ->on('APPROVE', 'approved', 'approve_manager')
                        ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['manager']]), G::ref('amountUpTo', ['max' => 50000])))
                    ->on('APPROVE', 'approved', 'approve_board')->guard('hasAnyRole', ['roles' => ['board']])
                    ->on('REJECT', 'rejected')
                        ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['analyst', 'manager']]), G::ref('payloadHas', ['field' => 'motive'])))
                    ->on('ESCALATE', 'awaiting_approval')->internal()
                ->finalState('approved')
                ->finalState('rejected')
            ->region('review', 'idle')
                ->state('idle')
                    ->on('REJECT', 'idle', 'reject_review')->guard('payloadHas', ['field' => 'note'])
            ->build();
    }

    public static function approvalGuards(): GuardRegistry
    {
        return Spec::guardRegistry()
            ->register('hasAnyRole', static fn(array $p): ISpecification => (new PredicateSpecification(
                static fn(TransitionCandidate $c): bool => array_intersect(is_array($c->context) ? ($c->context['roles'] ?? []) : [], $p['roles']) !== []
            ))->because('Your role cannot do this.')->withCode('ROLE_NOT_ALLOWED'))
            ->register('amountUpTo', static fn(array $p): ISpecification => Spec::property('context.amount', Spec::lessThanOrEqualTo($p['max']))
                ->because('The amount is above your approval limit.')->withCode('ABOVE_APPROVAL_LIMIT'));
    }

    /** The claim machine of the documentation page (deadline, payloadHas, effects, a parallel region). */
    public static function claimMachine(): MachineDefinition
    {
        return Spec::machine('claim', 1)
            ->region('progress', 'triage')
                ->state('triage')
                    ->on('START_ANALYSIS', 'analysis')->action('notify_analyst')
                ->state('analysis')->deadline('P5D', 'ANALYSIS_OVERDUE')
                    ->on('APPROVE', 'approved')
                    ->on('CANCEL', 'cancelled')->guard('payloadHas', ['field' => 'motive'])->action('mail_cancellation')
                    ->on('ANALYSIS_OVERDUE', 'late')
                ->state('late')
                    ->on('APPROVE', 'approved')
                ->finalState('approved')
                ->finalState('cancelled')
            ->region('pending', 'none')
                ->state('none')->on('REGISTER_PENDING', 'open')
                ->state('open')->on('RESOLVE_PENDING', 'none')
            ->build();
    }

    public static function quoteGuards(): GuardRegistry
    {
        return Spec::guardRegistry()->register('hasRole', static function (array $params): ISpecification {
            $roles = $params['roles'] ?? [];
            if ($roles === []) {
                return Spec::alwaysTrue();
            }

            return Spec::property('context.role', Spec::in($roles))->because('The user role is not allowed to take this action.')->withCode('ROLE_NOT_ALLOWED');
        });
    }

    /**
     * A seeded walk over the quote machine: at every snapshot, nextEvents() and can() of every event type.
     *
     * @return list<mixed>
     */
    private static function quoteAnswers(): array
    {
        $definition = MachineDefinition::fromJson((string) file_get_contents(self::QUOTE_FIXTURE));
        $machine = Spec::stateMachine(self::quoteGuards());
        $out = [];
        foreach ([4501, 4502, 4503, 4504, 4505, 4506] as $seed) {
            $random = new Randomizer(new Mt19937($seed));
            $at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');
            $snapshot = $machine->initialSnapshot($definition, null, $at)->snapshot;
            for ($step = 0; $step < 12 && !$snapshot->isDone(); $step++) {
                $row = ['next' => [], 'can' => []];
                foreach ($machine->nextEvents($definition, $snapshot, null, $at, true) as $type => $result) {
                    $row['next'][$type] = $result->toArray();
                }
                foreach ([...array_keys($row['next']), 'NOPE'] as $type) {
                    $payload = $random->getInt(0, 1) === 1 ? ['motive' => 'm', 'observation' => 'o'] : [];
                    $row['can'][$type] = $machine->can($definition, $snapshot, Event::external((string) $type, $at, $payload))->toArray();
                }
                $out[] = $row;
                $accepted = array_values(array_filter(array_keys($row['next']), static fn(string $t): bool => $row['next'][$t]['status'] === 'accepted'));
                if ($accepted === []) {
                    break;
                }
                $type = $accepted[$random->getInt(0, count($accepted) - 1)];
                $at = $at->modify('+1 hour');
                $snapshot = $machine->transition($definition, $snapshot, Event::external($type, $at, ['motive' => 'm', 'observation' => 'o']))->snapshot;
            }
        }

        return $out;
    }

    /**
     * can() and nextEvents() of the approval machine for every role and amount.
     *
     * @return array<string, mixed>
     */
    private static function approvalAnswers(): array
    {
        $definition = self::approvalMachine();
        $machine = Spec::stateMachine(self::approvalGuards());
        $at = new DateTimeImmutable('2026-10-21T09:00:00Z');
        $start = $machine->initialSnapshot($definition, null, $at)->snapshot;
        $out = [];
        foreach ([['analyst'], ['manager'], ['board'], ['analyst', 'manager'], [], ['intern']] as $roles) {
            foreach ([8000, 90000] as $amount) {
                $context = ['roles' => $roles, 'amount' => $amount];
                $key = implode('+', $roles) . '@' . $amount;
                foreach (['APPROVE', 'REJECT', 'ESCALATE', 'UNKNOWN'] as $type) {
                    foreach ([[], ['motive' => 'x', 'note' => 'y']] as $i => $payload) {
                        $out[$key]['can'][$type][$i] = $machine->can($definition, $start, Event::external($type, $at, $payload), $context)->toArray();
                    }
                }
                foreach ([false, true] as $internal) {
                    $out[$key]['next'][$internal ? 'internal' : 'external'] = array_map(
                        static fn($r): array => $r->toArray(),
                        $machine->nextEvents($definition, $start, $context, $at, $internal)
                    );
                }
            }
        }

        return $out;
    }

    /**
     * A runner flow over the claim machine: start, accepted, rejected, unhandled and repeated events,
     * deadlines, effects dispatched; then everything the stores answer.
     *
     * @return array<string, mixed>
     */
    private static function runnerAnswers(MachinePersistence $persistence, ?PDO $pdo): array
    {
        $calls = [];
        $handlers = (new EffectHandlerRegistry())
            ->register('notify_analyst', static function (Effect $effect, string $key, string $instanceId) use (&$calls): void {
                $calls[] = [$effect->action, $key, $instanceId, func_num_args()];
            })
            ->register('mail_cancellation', static function (Effect $effect, string $key, string $instanceId) use (&$calls): void {
                $calls[] = [$effect->action, $key, $instanceId, func_num_args()];
            });
        $runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([self::claimMachine()]), $persistence, $handlers);
        $at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');
        $results = [];
        foreach (['c-1', 'c-2', 'c-3'] as $id) {
            $results[] = $runner->start($id, 'claim', 1, null, $at, 'ana')->toArray();
        }
        $steps = [
            ['c-1', 'START_ANALYSIS', [], 'k1'], ['c-1', 'START_ANALYSIS', [], 'k1'], ['c-1', 'CANCEL', [], 'k2'],
            ['c-1', 'REGISTER_PENDING', ['why' => 'doc'], null], ['c-2', 'START_ANALYSIS', [], null], ['c-2', 'CANCEL', ['motive' => 'Duplicate'], 'k3'],
            ['c-2', 'APPROVE', [], null], ['c-3', 'NOPE', [], null], ['c-3', 'START_ANALYSIS', ['x' => 1], 'k4'],
        ];
        foreach ($steps as $i => [$id, $type, $payload, $key]) {
            $results[] = $runner->send($id, Event::external($type, $at->modify('+' . ($i + 1) . ' minutes'), $payload, $key), null, 'bob')->toArray();
        }
        $deliveries = $runner->deliverDue($at->modify('+6 days'), actor: 'scheduler');
        $results[] = array_map(static fn($d): array => [$d->deadline->toArray(), $d->result?->toArray(), $d->skipped], $deliveries);
        $clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-10-30T00:00:00Z');
        $report = (new EffectDispatcher($persistence->outbox, $handlers, null, $clock))->dispatchPending();
        $out = [
            'results' => $results,
            'dispatched' => $report->deliveredCount(),
            'calls' => $calls,
        ];
        foreach (['c-1', 'c-2', 'c-3'] as $id) {
            $out['instances'][$id] = $runner->load($id)->toArray();
            $out['history'][$id] = array_map(static fn($h): array => $h->toArray(), $persistence->history->findByInstance($id));
            $out['outbox'][$id] = array_map(static fn($o): array => $o->toArray(), $persistence->outbox->findByInstance($id));
            foreach (['k1', 'k2', 'k3', 'k4'] as $key) {
                $out['idempotency'][$id][$key] = $persistence->idempotency->find($id, $key)?->toArray();
            }
        }
        if ($pdo !== null) {
            foreach (['state_machine_instance', 'state_machine_region', 'state_machine_history', 'state_machine_outbox', 'state_machine_idempotency'] as $table) {
                $out['rows'][$table] = $pdo->query("SELECT * FROM {$table} ORDER BY 1, 2, 3")->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        return $out;
    }

    /**
     * Runs a DDL script: drops `--` comment lines and executes each `;`-terminated statement.
     */
    public static function loadDdl(PDO $pdo, string $ddl): void
    {
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
