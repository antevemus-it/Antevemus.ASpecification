<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\FailureSeverity;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\StateMachine\ActionRef;
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\EffectPhase;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidSnapshotException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownGuardException;
use Antevemus\ASpecification\StateMachine\GuardExpression;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\InStateSpecification;
use Antevemus\ASpecification\StateMachine\MachineBuilder;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineDefinitionError;
use Antevemus\ASpecification\StateMachine\MachineDefinitionValidator;
use Antevemus\ASpecification\StateMachine\MachineDefinitionWarning;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\StateMachine;
use Antevemus\ASpecification\StateMachine\TakenTransition;
use Antevemus\ASpecification\StateMachine\TransitionCandidate;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use Antevemus\ASpecification\StateMachine\TransitionStatus;
use Antevemus\ASpecification\Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Module17_StateMachineTest - Suíte da Máquina de Estados Dirigida por Especificações (1.8.0)
 *
 * Forward 023, fases 2 a 4: modelo de definição (builder, ida e volta, fingerprint), núcleo puro
 * (transition, can, nextEvents, initialSnapshot), validador da definição (D17) e a aceitação com a
 * máquina real da cotação da Virttus (`tests/Fixtures/state_machine/virttus_quote.v1.json`, montada
 * só a partir do grafo de `docs/state-machine/MAQUINA-REAL-VIRTTUS-2026-10-10.md` §2).
 *
 * Runner, persistência, outbox e DDL (D12-D16, fase 5) estão no Module18_StateMachineRunnerTest.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module17_StateMachineTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/state_machine/virttus_quote.v1.json';

    /** The spine that actually runs in production (MAQUINA-REAL §2). */
    private const SPINE = [
        ['COMPLETE_CALCULATION', 'registering', 'calculation_done', 'complete_calculation'],
        ['REQUEST_CLIENT_DOCUMENTS', 'calculation_done', 'awaiting_client_documents', 'request_client_documents'],
        ['CLIENT_DOCUMENTS_RECEIVED', 'awaiting_client_documents', 'awaiting_hiring_proposal', 'client_documents_received'],
        ['SEND_PROPOSAL', 'awaiting_hiring_proposal', 'proposal_sent', 'send_proposal'],
        ['APPROVE_PROPOSAL_QUOTATION', 'proposal_sent', 'approve_proposal_quotation', 'approve_proposal_quotation'],
        ['COMPLETE_PROPOSAL', 'approve_proposal_quotation', 'completed', 'complete_proposal'],
    ];

    public function run(): void
    {
        $this->testDefinitionModelAndBuilder();
        $this->testStructuralInvariants();
        $this->testRoundTripAndFingerprint();
        $this->testGuardRegistryAndBuiltIns();
        $this->testSnapshotImmutabilityAndSerialization();
        $this->testVirttusFixtureShape();
        $this->testVirttusValidation();
        $this->testVirttusHappySpine();
        $this->testVirttusPendingRegionKeepsProgress();
        $this->testCanAndNextEventsReasons();
        $this->testAllOrNothingAcrossRegions();
        $this->testGuardsReadSnapshotBeforeEvent();
        $this->testUnhandledAndFinalStatesInert();
        $this->testRegionLevelTransitionsPriority();
        $this->testInternalEvents();
        $this->testDeadlines();
        $this->testEffectsOrderAndSelfTransitions();
        $this->testGuardExceptionBecomesErrorFailure();
        $this->testValidatorErrors();
        $this->testValidatorWarnings();
        $this->testPurityAndDeterminism();
        $this->testSnapshotMustMatchDefinition();
        $this->testSpecFacade();
        $this->testReadme14_1MinimalMachine();
        $this->testReadme14_2ParallelRegionsPendingFlag();
        $this->testReadme14_3CanAndNextEvents();
        $this->testReadme14_4DefinitionAsDataAndValidator();
    }

    // ==========================================
    // Helpers
    // ==========================================

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    private function loadFixture(): MachineDefinition
    {
        $json = file_get_contents(self::FIXTURE);
        $this->assertTrue(is_string($json), 'Fixture virttus_quote.v1.json must be readable');

        return MachineDefinition::fromJson((string) $json);
    }

    /**
     * Guards of the Virttus fixture: built-ins plus `hasRole`. An empty role list means "anyone"
     * (the legacy `roles_allowed` semantics), which the algebra proves a tautology.
     */
    private function virttusGuards(): GuardRegistry
    {
        return Spec::guardRegistry()->register('hasRole', static function (array $params): ISpecification {
            $roles = $params['roles'] ?? [];
            if ($roles === []) {
                return Spec::alwaysTrue();
            }

            return Spec::property('context.role', Spec::in($roles))
                ->because('The user role is not allowed to take this action.')
                ->withCode('ROLE_NOT_ALLOWED');
        });
    }

    /**
     * Sends a sequence of external events, asserting each is accepted.
     *
     * @param list<string> $events
     */
    private function sendAll(StateMachine $machine, MachineDefinition $definition, MachineSnapshot $snapshot, array $events, DateTimeImmutable $at): MachineSnapshot
    {
        foreach ($events as $type) {
            $result = $machine->transition($definition, $snapshot, Event::external($type, $at), ['role' => 'operator']);
            $this->assertTrue($result->isAccepted(), "Event {$type} should be accepted: " . $result->getReason());
            $snapshot = $result->getSnapshot();
        }

        return $snapshot;
    }

    /**
     * Recursively reverses the key order of associative arrays (lists keep their order).
     */
    private function reverseKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = array_map(fn(mixed $v): mixed => $this->reverseKeys($v), $value);

        return array_is_list($out) ? $out : array_reverse($out, true);
    }

    /**
     * @param list<Effect> $effects
     * @return list<string>
     */
    private function effectLabels(array $effects): array
    {
        return array_map(
            static fn(Effect $e): string => "{$e->phase->value}:{$e->region}:{$e->action}",
            $effects
        );
    }

    // ==========================================
    // 1. Definition model
    // ==========================================

    private function testDefinitionModelAndBuilder(): void
    {
        $definition = Spec::machine('document', 1)
            ->region('lifecycle', 'pending')
                ->state('pending')
                    ->on('DELIVER', 'delivered')->guard('payloadHas', ['field' => 'file'])->action('store_file', ['bucket' => 'docs'])
                ->state('delivered')->deadline('P30D', 'EXPIRE')->entry('notify', ['template' => 'delivered'])
                    ->on('VALIDATE', 'validated', 'validate_document')
                    ->on('EXPIRE', 'expired')
                ->finalState('validated')
                ->finalState('expired')
            ->build();

        $this->assertInstanceOf(MachineDefinition::class, $definition);
        $this->assertEquals('document', $definition->key);
        $this->assertEquals(1, $definition->version);
        $this->assertEquals(['lifecycle'], $definition->regionNames());
        $region = $definition->getRegion('lifecycle');
        $this->assertEquals('pending', $region->initial);
        $this->assertEquals(['pending', 'delivered', 'validated', 'expired'], $region->stateNames());
        $this->assertTrue($region->getState('validated')->final);
        $this->assertFalse($region->getState('pending')->final);
        $this->assertEquals('P30D', $region->getState('delivered')->deadline->after);
        $this->assertEquals('EXPIRE', $region->getState('delivered')->deadline->event);

        // Generated ids are deterministic (region.state.EVENT); explicit ones are kept.
        $this->assertTrue($definition->getTransition('lifecycle.pending.DELIVER') !== null, 'Generated id lifecycle.pending.DELIVER');
        $this->assertTrue($definition->getTransition('validate_document') !== null, 'Explicit id kept');
        $this->assertTrue($definition->getTransition('lifecycle.delivered.EXPIRE') !== null, 'Generated id lifecycle.delivered.EXPIRE');
        $this->assertCount(3, $definition->allTransitions());

        $deliver = $definition->getTransition('lifecycle.pending.DELIVER');
        $this->assertEquals(['guard' => 'payloadHas', 'params' => ['field' => 'file']], $deliver->guard->toArray());
        $this->assertEquals([['action' => 'store_file', 'params' => ['bucket' => 'docs']]], array_map(static fn(ActionRef $a) => $a->toArray(), $deliver->actions));

        // Several guard() calls are combined with "and".
        $multi = Spec::machine('multi', 1)
            ->region('r', 'a')
                ->state('a')->on('GO', 'b', 'go')->guard('payloadHas', ['field' => 'x'])->guard(GuardExpression::not(GuardExpression::ref('inState', ['state' => 'r.b'])))
                ->finalState('b')
            ->build();
        $this->assertEquals(
            ['and' => [
                ['guard' => 'payloadHas', 'params' => ['field' => 'x']],
                ['not' => ['guard' => 'inState', 'params' => ['state' => 'r.b']]],
            ]],
            $multi->getTransition('go')->guard->toArray()
        );

        // Builder and array produce the same definition.
        $fromArray = MachineDefinition::fromArray([
            'key' => 'multi',
            'version' => 1,
            'regions' => [[
                'name' => 'r',
                'initial' => 'a',
                'states' => [
                    ['name' => 'a', 'transitions' => [[
                        'id' => 'go', 'event' => 'GO', 'target' => 'b',
                        'guard' => ['and' => [
                            ['guard' => 'payloadHas', 'params' => ['field' => 'x']],
                            ['not' => ['guard' => 'inState', 'params' => ['state' => 'r.b']]],
                        ]],
                    ]]],
                    ['name' => 'b', 'final' => true],
                ],
            ]],
        ]);
        $this->assertTrue($fromArray == $multi, 'Builder and fromArray must produce equal definitions');
        $this->assertEquals($multi->fingerprint(), $fromArray->fingerprint());

        // Guard expression helpers: string shorthand, annotations, refs().
        $expression = GuardExpression::allOf('isManager', GuardExpression::anyOf(
            GuardExpression::ref('payloadHas', ['field' => 'motive']),
            GuardExpression::not('isBlocked')
        ))->because('Manager approval required.')->withCode('NEEDS_MANAGER');
        $this->assertEquals(['isManager', 'payloadHas', 'isBlocked'], array_map(static fn($r) => $r->name, $expression->refs()));
        $this->assertEquals('NEEDS_MANAGER', $expression->toArray()['code']);
        $this->assertEquals('Manager approval required.', $expression->toArray()['message']);
        $this->assertTrue(GuardExpression::fromArray($expression->toArray()) == $expression, 'Guard expression round-trip');
        $this->assertTrue(GuardExpression::fromArray('isManager') == GuardExpression::ref('isManager'), 'String shorthand');
    }

    private function testStructuralInvariants(): void
    {
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('dup', 1)
            ->region('r', 'a')
                ->state('a')->on('GO', 'b', 'same')
                ->state('b')->on('BACK', 'a', 'same')
            ->build());
        $this->assertEquals([MachineDefinitionError::KIND_DUPLICATE], $e->getErrorKinds());
        $this->assertTrue(str_contains($e->getMessage(), 'same'), 'Duplicate transition id named in the message');

        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('dup', 1)
            ->region('r', 'a')->state('a')->state('a')->build());
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('dup', 1)
            ->region('r', 'a')->state('a')->region('r', 'a')->state('a')->build());
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('m', 0)->build());
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('m', 1)->build());
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('m', 1)
            ->region('r', 'a')->state('a')->deadline('3 days', 'EXPIRE'));
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('m', 1)
            ->region('r.x', 'a')->state('a')->build());
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::machine('m', 1)
            ->region('r', 'a')->state('a')->entry('notify', ['callback' => static fn() => null]));
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => GuardExpression::ref('g', ['obj' => new \stdClass()]));
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => GuardExpression::allOf());
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => GuardExpression::fromArray(['guard' => 'a', 'not' => 'b']));
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => MachineDefinition::fromJson('{not json'));
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => MachineDefinition::fromArray(['key' => 'm', 'version' => '1', 'regions' => []]));
        $this->assertThrows(InvalidArgumentException::class, fn() => Event::external('', $this->at('2026-10-10T10:00:00+00:00')));

        // Generated ids never collide with explicit ones declared later.
        $definition = MachineDefinition::fromArray([
            'key' => 'ids', 'version' => 1,
            'regions' => [[
                'name' => 'r', 'initial' => 'a',
                'states' => [
                    ['name' => 'a', 'transitions' => [['event' => 'GO', 'target' => 'b']]],
                    ['name' => 'b', 'transitions' => [['id' => 'r.a.GO', 'event' => 'BACK', 'target' => 'a']]],
                ],
            ]],
        ]);
        $this->assertTrue($definition->getTransition('r.a.GO#2') !== null, 'Generated id skips the explicit one');
        $this->assertEquals('BACK', $definition->getTransition('r.a.GO')->event);
    }

    private function testRoundTripAndFingerprint(): void
    {
        $definition = $this->loadFixture();

        $this->assertTrue(MachineDefinition::fromArray($definition->toArray()) == $definition, 'fromArray(toArray(d)) == d');
        $this->assertTrue(MachineDefinition::fromJson($definition->toJson()) == $definition, 'fromJson(toJson(d)) == d');
        $this->assertTrue(MachineDefinition::fromJson($definition->toJson(false)) == $definition, 'compact JSON round-trip');
        $this->assertEquals($definition->toArray(), MachineDefinition::fromArray($definition->toArray())->toArray());

        $fingerprint = $definition->fingerprint();
        $this->assertEquals(64, strlen($fingerprint));
        $this->assertEquals($fingerprint, $this->loadFixture()->fingerprint(), 'Fingerprint stable between loads');
        $this->assertEquals(
            $fingerprint,
            MachineDefinition::fromArray($this->reverseKeys($definition->toArray()))->fingerprint(),
            'Fingerprint independent of key order'
        );
        $this->assertEquals(
            $fingerprint,
            MachineDefinition::fromArray($this->reverseKeys(json_decode((string) file_get_contents(self::FIXTURE), true)))->fingerprint(),
            'Fingerprint independent of key order of the source JSON'
        );
        $this->assertEquals('425491b681f0218a79421efbd0f0ede72161a150776a632e29ba15aa944c262d', $fingerprint, 'Pinned fingerprint of the v1 fixture');

        // Any semantic change changes the fingerprint.
        $changed = $definition->toArray();
        $changed['regions'][0]['states'][0]['transitions'][0]['guard']['code'] = 'OTHER';
        $this->assertFalse(MachineDefinition::fromArray($changed)->fingerprint() === $fingerprint, 'Guard change changes the fingerprint');
        $reordered = $definition->toArray();
        $reordered['regions'][0]['states'][0]['transitions'] = array_reverse($reordered['regions'][0]['states'][0]['transitions']);
        $this->assertFalse(MachineDefinition::fromArray($reordered)->fingerprint() === $fingerprint, 'Transition order is semantic');
        $bumped = $definition->toArray();
        $bumped['version'] = 2;
        $this->assertFalse(MachineDefinition::fromArray($bumped)->fingerprint() === $fingerprint, 'Version is part of the fingerprint');

        // The canonical array is JSON-friendly: no object anywhere.
        $hasObject = false;
        array_walk_recursive($definition->toArray()['regions'], static function ($v) use (&$hasObject): void {
            $hasObject = $hasObject || is_object($v);
        });
        $this->assertFalse($hasObject, 'toArray() holds no object');
    }

    private function testGuardRegistryAndBuiltIns(): void
    {
        $guards = new GuardRegistry();
        $this->assertEquals([GuardRegistry::IN_STATE, GuardRegistry::PAYLOAD_HAS, GuardRegistry::EVENT_ORIGIN], $guards->names());
        $this->assertFalse((new GuardRegistry(false))->has('inState'), 'Built-ins can be disabled');

        $snapshot = new MachineSnapshot('m', 1, ['progress' => 'proposal_sent', 'pending' => 'open']);
        $at = $this->at('2026-10-10T10:00:00+00:00');
        $candidate = new TransitionCandidate(['role' => 'analyst'], Event::external('X', $at, ['motive' => 'late', 'blank' => '  ', 'nested' => ['ok' => 1]]), $snapshot);

        $inOpen = $guards->build('inState', ['state' => 'pending.open']);
        $this->assertTrue($inOpen->isSatisfiedBy($candidate));
        $this->assertFalse($guards->build('inState', ['state' => 'pending.none'])->isSatisfiedBy($candidate));
        $failure = $guards->build('inState', ['state' => 'progress.registering'])->evaluate($candidate);
        $this->assertEquals("Region 'progress' is not in state 'registering' (current: 'proposal_sent').", $failure->getReasons()[0]);

        $this->assertTrue($guards->build('payloadHas', ['field' => 'motive'])->isSatisfiedBy($candidate));
        $this->assertTrue($guards->build('payloadHas', ['field' => 'nested.ok'])->isSatisfiedBy($candidate));
        $this->assertFalse($guards->build('payloadHas', ['field' => 'blank'])->isSatisfiedBy($candidate));
        $this->assertFalse($guards->build('payloadHas', ['field' => 'observation'])->isSatisfiedBy($candidate));
        $this->assertEquals(
            ["The event payload must provide 'observation'."],
            $guards->build('payloadHas', ['field' => 'observation'])->evaluate($candidate)->getReasons()
        );
        $this->assertTrue($guards->build('eventOrigin', ['origin' => 'external'])->isSatisfiedBy($candidate));
        $this->assertFalse($guards->build('eventOrigin', ['origin' => 'internal'])->isSatisfiedBy($candidate));

        // Built-in factories refuse malformed parameters; unknown names throw.
        $this->assertThrows(InvalidArgumentException::class, fn() => $guards->build('inState', ['state' => 'nodot']));
        $this->assertThrows(InvalidArgumentException::class, fn() => $guards->build('inState'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $guards->build('payloadHas'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $guards->build('eventOrigin', ['origin' => 'elsewhere']));
        $this->assertThrows(UnknownGuardException::class, fn() => $guards->build('nope'));

        // Algebra of the built-ins: one current state per region.
        $this->assertTrue($inOpen->isDisjointWith($guards->build('inState', ['state' => 'pending.none'])), 'Same region, different states: disjoint');
        $this->assertFalse($inOpen->isDisjointWith($guards->build('inState', ['state' => 'progress.registering'])), 'Different regions: not disjoint');
        $this->assertTrue($inOpen->isDisjointWith($inOpen->not()), 'A ⟂ ¬A');
        $this->assertTrue(
            $guards->build('eventOrigin', ['origin' => 'internal'])->isDisjointWith($guards->build('eventOrigin', ['origin' => 'external'])),
            'Different origins: disjoint'
        );

        // Compilation of expressions: annotations reach the compiled specification.
        $compiled = $guards->compile(
            GuardExpression::not(GuardExpression::ref('inState', ['state' => 'pending.open']))
                ->because('Resolve the pending item first.')
                ->withCode('PENDING_OPEN')
        );
        $result = $compiled->evaluate($candidate);
        $this->assertFalse($result->isSatisfied);
        $this->assertEquals(['PENDING_OPEN'], $result->getCodes());
        $this->assertEquals(['Resolve the pending item first.'], $result->getReasons());

        $or = $guards->compile(GuardExpression::anyOf(
            GuardExpression::ref('inState', ['state' => 'pending.none']),
            GuardExpression::ref('payloadHas', ['field' => 'motive'])
        ));
        $this->assertTrue($or->isSatisfiedBy($candidate));
        $and = $guards->compile(GuardExpression::allOf(
            GuardExpression::ref('inState', ['state' => 'pending.none']),
            GuardExpression::ref('payloadHas', ['field' => 'observation'])
        ));
        $this->assertCount(2, $and->evaluate($candidate)->failures, 'Unannotated conjunction reports every failing operand');

        // A guard factory must return a specification.
        $bad = (new GuardRegistry())->register('bad', static fn(array $p) => 'not a spec');
        $this->assertThrows(\Antevemus\ASpecification\StateMachine\Exceptions\StateMachineException::class, fn() => $bad->build('bad'));

        // Guards address the candidate through property paths.
        $role = Spec::property('context.role', Spec::equalTo('analyst'));
        $this->assertTrue($role->isSatisfiedBy($candidate), 'context.role path');
        $this->assertTrue(Spec::property('event.payload.motive', Spec::equalTo('late'))->isSatisfiedBy($candidate), 'event.payload path');
    }

    private function testSnapshotImmutabilityAndSerialization(): void
    {
        $due = $this->at('2026-10-13T10:00:00.123456+00:00');
        $snapshot = new MachineSnapshot('m', 3, ['a' => 'x', 'b' => 'y'], ['a' => $due]);
        $this->assertEquals('x', $snapshot->stateOf('a'));
        $this->assertTrue($snapshot->isIn('a', 'x'));
        $this->assertTrue($snapshot->isIn('b.y'));
        $this->assertFalse($snapshot->isIn('b.x'));
        $this->assertFalse($snapshot->isIn('nodot'));
        $this->assertEquals(null, $snapshot->dueAt('b'));
        $this->assertFalse($snapshot->isDone());

        $moved = $snapshot->withState('a', 'z');
        $this->assertEquals('x', $snapshot->stateOf('a'), 'withState does not mutate');
        $this->assertEquals('z', $moved->stateOf('a'));
        $this->assertEquals(null, $moved->dueAt('a'), 'withState replaces the due instant');
        $this->assertTrue($snapshot->dueAt('a') == $due);
        $this->assertTrue($snapshot->withDone(true)->isDone() && !$snapshot->isDone(), 'withDone does not mutate');

        $array = $snapshot->toArray();
        $this->assertEquals('2026-10-13T10:00:00.123456+00:00', $array['due_at']['a']);
        $this->assertEquals(['a' => 'x', 'b' => 'y'], $array['states']);
        $this->assertEquals($array, MachineSnapshot::fromArray($array)->toArray(), 'Snapshot round-trip keeps microseconds');
        $this->assertEquals($array, MachineSnapshot::fromArray(json_decode((string) json_encode($array), true))->toArray(), 'Snapshot JSON round-trip');

        $this->assertThrows(InvalidSnapshotException::class, fn() => $snapshot->stateOf('nope'));
        $this->assertThrows(InvalidSnapshotException::class, fn() => new MachineSnapshot('m', 1, []));
        $this->assertThrows(InvalidSnapshotException::class, fn() => new MachineSnapshot('m', 1, ['a' => 'x'], ['b' => null]));
        $this->assertThrows(InvalidSnapshotException::class, fn() => MachineSnapshot::fromArray(['machine_key' => 'm']));
    }

    // ==========================================
    // 2. Acceptance: the Virttus quote machine
    // ==========================================

    private function testVirttusFixtureShape(): void
    {
        $definition = $this->loadFixture();
        $this->assertEquals(['progress', 'pending'], $definition->regionNames());

        $progress = $definition->getRegion('progress');
        $this->assertCount(20, $progress->states, 'progress has the 20 states of the graph (21 minus pending_registered)');
        $this->assertFalse($progress->hasState('pending_registered'), 'pending_registered became the pending region');
        $this->assertEquals('registering', $progress->initial);
        $finals = array_values(array_map(static fn($s) => $s->name, array_filter($progress->states, static fn($s) => $s->final)));
        $this->assertEquals(['cancelled', 'completed', 'rejected'], $this->sorted($finals));
        $this->assertFalse($progress->getState('approved')->final, 'approved keeps exits: not final in v1');
        $this->assertFalse($progress->getState('rejected_by_scoring')->final, 'rejected_by_scoring keeps exits: not final in v1');

        $progressTransitions = array_filter($definition->allTransitions(), static fn($e) => $e['region'] === 'progress');
        $this->assertCount(48, $progressTransitions, '52 transitions minus the 4 of the pending state');
        foreach (['register_pending', 'resolve_pending', 'reject_by_scoring_in_resolve_pending', 'cancel_from_pending_registered'] as $removed) {
            $this->assertFalse(
                in_array($removed, array_map(static fn($e) => $e['transition']->id, $progressTransitions), true),
                "{$removed} is not a progress transition"
            );
        }

        $pending = $definition->getRegion('pending');
        $this->assertEquals(['none', 'open'], $pending->stateNames());
        $this->assertEquals('none', $pending->initial);
        $this->assertEquals('open', $definition->getTransition('register_pending')->target);
        $this->assertEquals('REGISTER_PENDING', $definition->getTransition('register_pending')->event);
        $this->assertEquals('none', $definition->getTransition('resolve_pending')->target);
        $this->assertEquals('RESOLVE_PENDING', $definition->getTransition('resolve_pending')->event);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);
        return $values;
    }

    private function testVirttusValidation(): void
    {
        $definition = $this->loadFixture();
        $guards = $this->virttusGuards();

        // v1 is accepted, with warnings only.
        $warnings = MachineDefinitionValidator::validate($definition, $guards);
        $report = MachineDefinitionValidator::analyze($definition, $guards);
        $this->assertTrue($report->isValid());
        $this->assertCount(count($warnings), $report->warnings);

        $deadEnds = $report->warningsOfKind(MachineDefinitionWarning::KIND_DEAD_END_STATE);
        $this->assertCount(1, $deadEnds, 'Only awaiting_signatures is a trap');
        $this->assertEquals('awaiting_signatures', $deadEnds[0]->state);
        $this->assertEquals('progress', $deadEnds[0]->region);

        $tautologies = $report->warningsOfKind(MachineDefinitionWarning::KIND_TAUTOLOGICAL_GUARD);
        $this->assertCount(1, $tautologies, 'cancel_from_policy: empty role list lets anyone cancel');
        $this->assertEquals('cancel_from_policy', $tautologies[0]->transitionId);
        $this->assertEquals('CANCEL', $tautologies[0]->eventType);

        $this->assertEquals([], $report->warningsOfKind(MachineDefinitionWarning::KIND_UNREACHABLE_STATE));
        $this->assertEquals([], $report->warningsOfKind(MachineDefinitionWarning::KIND_SHADOWED_TRANSITION));
        $this->assertEquals([], $report->warningsOfKind(MachineDefinitionWarning::KIND_OVERLAPPING_GUARDS));
        $this->assertCount(2, $report->warnings);

        // Without the hasRole factory the definition is refused.
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => MachineDefinitionValidator::validate($definition, new GuardRegistry()));
        $this->assertEquals([MachineDefinitionError::KIND_UNKNOWN_GUARD], $e->getErrorKinds());

        // The literal version (approved and rejected_by_scoring final WITH exits) is refused.
        $literal = $definition->toArray();
        foreach ($literal['regions'][0]['states'] as $i => $state) {
            if (in_array($state['name'], ['approved', 'rejected_by_scoring'], true)) {
                $literal['regions'][0]['states'][$i]['final'] = true;
            }
        }
        $literalDefinition = MachineDefinition::fromArray($literal);
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => MachineDefinitionValidator::validate($literalDefinition, $guards));
        $this->assertEquals(
            [MachineDefinitionError::KIND_FINAL_STATE_WITH_EXIT, MachineDefinitionError::KIND_FINAL_STATE_WITH_EXIT],
            $e->getErrorKinds()
        );
        $this->assertEquals(['rejected_by_scoring', 'approved'], array_map(static fn($err) => $err->state, $e->getErrors()));
        $this->assertTrue(str_contains($e->getMessage(), 'complete, cancel_from_approved'), 'The exits of approved are listed');
        $this->assertTrue(str_contains($e->getMessage(), 'cancel_from_rejected_by_scoring'), 'The exit of rejected_by_scoring is listed');
        $this->assertCount(2, $e->getWarnings(), 'The warnings travel with the refusal');
    }

    private function testVirttusHappySpine(): void
    {
        $definition = $this->loadFixture();
        $machine = new StateMachine($this->virttusGuards());
        $t0 = $this->at('2026-10-10T09:00:00-03:00');

        $start = $machine->initialSnapshot($definition, ['role' => 'operator'], $t0);
        $this->assertTrue($start->isAccepted());
        $this->assertEquals(null, $start->getEvent());
        $snapshot = $start->getSnapshot();
        $this->assertEquals(['progress' => 'registering', 'pending' => 'none'], $snapshot->getStates());
        $this->assertEquals('virttus.rental_guarantee_quote', $snapshot->machineKey);
        $this->assertEquals(1, $snapshot->machineVersion);
        $this->assertCount(2, $start->getTransitions(), 'One creation line per region');
        $this->assertEquals(null, $start->getTransitions()[0]->from);
        $this->assertEquals(null, $start->getTransitions()[0]->transitionId);

        foreach (self::SPINE as $step => [$event, $from, $to, $id]) {
            $result = $machine->transition($definition, $snapshot, Event::external($event, $t0->modify("+{$step} hours")), ['role' => 'operator']);
            $this->assertTrue($result->isAccepted(), "{$event} accepted from {$from}: " . $result->getReason());
            $this->assertEquals(TransitionStatus::ACCEPTED, $result->getStatus());
            $this->assertTrue($result->getReason()->isSatisfied);
            $this->assertCount(1, $result->getTransitions(), "One history line for {$event}");
            $taken = $result->getTransitions()[0];
            $this->assertEquals(['progress', $from, $to, $id, $event], [$taken->region, $taken->from, $taken->to, $taken->transitionId, $taken->eventType]);
            $this->assertFalse($taken->targetless);
            $this->assertEquals([], $result->getEffects(), 'The fixture declares no action');
            $snapshot = $result->getSnapshot();
            $this->assertEquals($to, $snapshot->stateOf('progress'));
            $this->assertEquals('none', $snapshot->stateOf('pending'));
        }

        // completed is final; pending (none/open) has no final state, so it is auxiliary and the instance is done.
        $this->assertTrue($definition->getRegion('progress')->getState('completed')->final);
        $this->assertTrue($snapshot->isDone(), 'done: every region with final states is final; pending is auxiliary');

        // Done is inert: no event is handled any more, not even a pending registration.
        $after = $machine->transition($definition, $snapshot, Event::external('CANCEL', $t0->modify('+1 day')));
        $this->assertTrue($after->isUnhandled(), 'CANCEL from completed is unhandled');
        $pending = $machine->transition($definition, $snapshot, Event::external('REGISTER_PENDING', $t0->modify('+1 day')));
        $this->assertTrue($pending->isUnhandled(), 'REGISTER_PENDING after completed is unhandled');
        $this->assertEquals([TransitionResult::CODE_MACHINE_DONE], $pending->getReason()->getCodes());
        $this->assertEquals([], $machine->nextEvents($definition, $snapshot, null, $t0), 'nextEvents is empty once done');

        // Integer-like names would become integer array keys: refused at build time.
        try {
            Spec::machine('numeric')->region('1', 'a')->state('a')->build();
            $this->assertTrue(false, 'region "1" must be refused');
        } catch (InvalidMachineDefinitionException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'integer string'), 'integer-string name refused');
        }
        $this->assertTrue(StateMachine::isDone(Spec::machine('flags')->region('f', 'off')->state('off')->on('ON', 'on')->state('on')->on('OFF', 'off')->build(), MachineSnapshot::fromArray(['machine_key' => 'flags', 'machine_version' => 1, 'states' => ['f' => 'off']])) === false, 'a machine with no final state is never done');
    }

    private function testVirttusPendingRegionKeepsProgress(): void
    {
        $definition = $this->loadFixture();
        $machine = new StateMachine($this->virttusGuards());
        $t0 = $this->at('2026-10-10T09:00:00-03:00');
        $snapshot = $this->sendAll(
            $machine,
            $definition,
            $machine->initialSnapshot($definition, null, $t0)->getSnapshot(),
            ['COMPLETE_CALCULATION', 'REQUEST_CLIENT_DOCUMENTS', 'CLIENT_DOCUMENTS_RECEIVED', 'SEND_PROPOSAL'],
            $t0
        );
        $this->assertEquals('proposal_sent', $snapshot->stateOf('progress'));

        // Register a pending item: only the pending region moves.
        $registered = $machine->transition($definition, $snapshot, Event::external('REGISTER_PENDING', $t0->modify('+1 day'), ['motive' => 'missing_document']));
        $this->assertTrue($registered->isAccepted());
        $this->assertCount(1, $registered->getTransitions());
        $this->assertEquals(['pending', 'none', 'open', 'register_pending'], [
            $registered->getTransitions()[0]->region,
            $registered->getTransitions()[0]->from,
            $registered->getTransitions()[0]->to,
            $registered->getTransitions()[0]->transitionId,
        ]);
        $open = $registered->getSnapshot();
        $this->assertEquals(['progress' => 'proposal_sent', 'pending' => 'open'], $open->getStates());

        // While pending is open, progress is blocked with the reason of the guard.
        $blocked = $machine->transition($definition, $open, Event::external('APPROVE_PROPOSAL_QUOTATION', $t0->modify('+2 days')));
        $this->assertTrue($blocked->isRejected());
        $this->assertFalse($blocked->isAccepted());
        $this->assertTrue($blocked->getSnapshot() === $open, 'Rejected: the very same snapshot comes back');
        $this->assertEquals([], $blocked->getTransitions());
        $this->assertEquals([], $blocked->getEffects());
        $this->assertEquals(['QUOTE_PENDING_OPEN'], $blocked->getReason()->getCodes());
        $this->assertEquals(
            ['The quote has an open pending item: resolve it before moving the quote forward.'],
            $blocked->getReason()->getReasons()
        );
        $failure = $blocked->getReason()->failures[0];
        $this->assertEquals(FailureSeverity::ERROR, $failure->severity);
        $this->assertEquals('progress', $failure->metadata['region']);
        $this->assertEquals('approve_proposal_quotation', $failure->metadata['transition_id']);
        $this->assertEquals('APPROVE_PROPOSAL_QUOTATION', $failure->metadata['event']);
        $this->assertEquals(['progress'], array_keys($blocked->getRejections()));

        // Cancel and reject by scoring stay possible while pending (as from pending_registered).
        $this->assertTrue($machine->can($definition, $open, Event::external('CANCEL', $t0))->isAccepted());
        $this->assertTrue($machine->can($definition, $open, Event::external('REJECT_BY_SCORING', $t0))->isAccepted());

        // A second registration is unhandled (pending is already open).
        $this->assertTrue($machine->transition($definition, $open, Event::external('REGISTER_PENDING', $t0))->isUnhandled());

        // Resolve: progress never left proposal_sent (the 11-of-13 defect is not representable).
        $resolved = $machine->transition($definition, $open, Event::external('RESOLVE_PENDING', $t0->modify('+3 days')));
        $this->assertTrue($resolved->isAccepted());
        $this->assertEquals(['progress' => 'proposal_sent', 'pending' => 'none'], $resolved->getSnapshot()->getStates());

        $approved = $machine->transition($definition, $resolved->getSnapshot(), Event::external('APPROVE_PROPOSAL_QUOTATION', $t0->modify('+4 days')));
        $this->assertTrue($approved->isAccepted());
        $this->assertEquals('approve_proposal_quotation', $approved->getSnapshot()->stateOf('progress'));

        // The same holds from any progress state: register/resolve at calculation_done.
        $calc = $this->sendAll($machine, $definition, $machine->initialSnapshot($definition, null, $t0)->getSnapshot(), ['COMPLETE_CALCULATION', 'REGISTER_PENDING', 'RESOLVE_PENDING'], $t0);
        $this->assertEquals(['progress' => 'calculation_done', 'pending' => 'none'], $calc->getStates());
    }

    private function testCanAndNextEventsReasons(): void
    {
        $definition = $this->loadFixture();
        $machine = new StateMachine($this->virttusGuards());
        $t0 = $this->at('2026-10-10T09:00:00-03:00');
        $sent = $this->sendAll($machine, $definition, $machine->initialSnapshot($definition, null, $t0)->getSnapshot(), ['COMPLETE_CALCULATION', 'REQUEST_CLIENT_DOCUMENTS', 'CLIENT_DOCUMENTS_RECEIVED', 'SEND_PROPOSAL', 'REGISTER_PENDING'], $t0);

        $next = $machine->nextEvents($definition, $sent, null, $t0);
        $this->assertEquals(
            ['APPROVE_PROPOSAL_QUOTATION', 'REJECT_BY_SCORING', 'SEND_TO_SCORING_ANALYSIS', 'REQUEST_PROOF_OF_LIFE', 'REQUEST_SIGNATURES', 'CANCEL', 'RESOLVE_PENDING'],
            array_keys($next),
            'Events of the current states, regions in order, each once'
        );
        $this->assertTrue($next['APPROVE_PROPOSAL_QUOTATION']->isRejected());
        $this->assertEquals(['QUOTE_PENDING_OPEN'], $next['APPROVE_PROPOSAL_QUOTATION']->getReason()->getCodes());
        $this->assertTrue($next['CANCEL']->isAccepted());
        $this->assertTrue($next['REJECT_BY_SCORING']->isAccepted());
        $this->assertTrue($next['RESOLVE_PENDING']->isAccepted());
        $this->assertTrue($next['REQUEST_SIGNATURES']->isRejected());

        // can() is transition() itself.
        $event = Event::external('APPROVE_PROPOSAL_QUOTATION', $t0);
        $this->assertEquals(
            json_encode($machine->transition($definition, $sent, $event)->toArray()),
            json_encode($machine->can($definition, $sent, $event)->toArray())
        );

        // Approval by role (D8): the reason is the specification's own message and code.
        $guards = Spec::guardRegistry()->register('hasRole', static fn(array $p): ISpecification =>
            Spec::property('context.role', Spec::in($p['roles']))
                ->because('Only an approver may approve this quote.')
                ->withCode('APPROVER_ROLE_REQUIRED'));
        $approval = Spec::machine('approval', 1)
            ->region('flow', 'awaiting_approval')
                ->state('awaiting_approval')
                    ->on('APPROVE', 'approved', 'approve')->guard('hasRole', ['roles' => ['approver', 'director']])
                    ->on('REJECT', 'rejected', 'reject')->guard('payloadHas', ['field' => 'motive'])
                ->finalState('approved')
                ->finalState('rejected')
            ->build();
        MachineDefinitionValidator::validate($approval, $guards);
        $approvalMachine = Spec::stateMachine($guards);
        $snapshot = $approvalMachine->initialSnapshot($approval, null, $t0)->getSnapshot();

        $denied = $approvalMachine->can($approval, $snapshot, Event::external('APPROVE', $t0), ['role' => 'analyst']);
        $this->assertTrue($denied->isRejected());
        $this->assertEquals(['APPROVER_ROLE_REQUIRED'], $denied->getReason()->getCodes());
        $this->assertEquals(['Only an approver may approve this quote.'], $denied->getReason()->getReasons());
        $this->assertTrue($approvalMachine->can($approval, $snapshot, Event::external('APPROVE', $t0), ['role' => 'director'])->isAccepted());

        $panel = $approvalMachine->nextEvents($approval, $snapshot, ['role' => 'analyst'], $t0);
        $this->assertEquals(['APPROVE', 'REJECT'], array_keys($panel));
        $this->assertEquals($denied->getReason()->getCodes(), $panel['APPROVE']->getReason()->getCodes(), 'nextEvents shows the same reason as can()');
        $this->assertEquals($denied->getReason()->getReasons(), $panel['APPROVE']->getReason()->getReasons());
        $this->assertTrue($panel['REJECT']->isRejected(), 'REJECT requires a motive in the payload');
        $this->assertEquals(["The event payload must provide 'motive'."], $panel['REJECT']->getReason()->getReasons());
        $this->assertTrue($approvalMachine->can($approval, $snapshot, Event::external('REJECT', $t0, ['motive' => 'income too low']))->isAccepted());
    }

    // ==========================================
    // 3. Core semantics
    // ==========================================

    private function twoRegionMachine(): MachineDefinition
    {
        return Spec::machine('aon', 1)
            ->region('a', 'a1')
                ->state('a1')->exit('a1_exit')
                    ->on('GO', 'a2', 'a_go')->action('a_act')
                ->state('a2')->entry('a2_entry')
            ->region('b', 'b1')
                ->state('b1')->exit('b1_exit')
                    ->on('GO', 'b2', 'b_go')->guard('payloadHas', ['field' => 'ok'])->action('b_act')
                ->state('b2')->entry('b2_entry')
            ->build();
    }

    private function testAllOrNothingAcrossRegions(): void
    {
        $definition = $this->twoRegionMachine();
        $machine = Spec::stateMachine();
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $snapshot = $machine->initialSnapshot($definition, null, $t0)->getSnapshot();

        // Region a would move, region b rejects: the whole event is rejected.
        $rejected = $machine->transition($definition, $snapshot, Event::external('GO', $t0));
        $this->assertTrue($rejected->isRejected());
        $this->assertTrue($rejected->getSnapshot() === $snapshot);
        $this->assertEquals(['a' => 'a1', 'b' => 'b1'], $rejected->getSnapshot()->getStates());
        $this->assertEquals([], $rejected->getTransitions());
        $this->assertEquals([], $rejected->getEffects());
        $this->assertEquals(['b'], array_keys($rejected->getRejections()));
        $this->assertEquals(["The event payload must provide 'ok'."], $rejected->getReason()->getReasons());
        $this->assertEquals('b_go', $rejected->getReason()->failures[0]->metadata['transition_id']);

        // Both pass: both move in the same step, effects in SCXML order across regions.
        $accepted = $machine->transition($definition, $snapshot, Event::external('GO', $t0, ['ok' => true]));
        $this->assertTrue($accepted->isAccepted());
        $this->assertEquals(['a' => 'a2', 'b' => 'b2'], $accepted->getSnapshot()->getStates());
        $this->assertEquals(['a', 'b'], array_map(static fn(TakenTransition $t) => $t->region, $accepted->getTransitions()));
        $this->assertEquals(
            ['exit:a:a1_exit', 'exit:b:b1_exit', 'transition:a:a_act', 'transition:b:b_act', 'entry:a:a2_entry', 'entry:b:b2_entry'],
            $this->effectLabels($accepted->getEffects())
        );
        $this->assertEquals('a_go', $accepted->getEffects()[0]->transitionId);
        $this->assertEquals('a1', $accepted->getEffects()[0]->state);
        $this->assertEquals('a1', $accepted->getEffects()[2]->state, 'Transition effects carry the source state');
        $this->assertEquals('a2', $accepted->getEffects()[4]->state);
    }

    private function testGuardsReadSnapshotBeforeEvent(): void
    {
        // Region b's guard reads region a; a moves on the same event. Both orders give the same result.
        foreach ([true, false] as $aFirst) {
            $builder = Spec::machine('rn04', 1);
            $addA = static fn(MachineBuilder $m) => $m->region('a', 'a1')->state('a1')->on('GO', 'a2', 'a_go')->state('a2')->end()->end();
            $addB = static fn(MachineBuilder $m) => $m->region('b', 'b1')
                ->state('b1')->on('GO', 'b2', 'b_go')->guard('inState', ['state' => 'a.a1'])
                ->state('b2')->end()->end();
            $aFirst ? $addB($addA($builder)) : $addA($addB($builder));
            $definition = $builder->build();

            $machine = new StateMachine();
            $t0 = $this->at('2026-10-10T10:00:00+00:00');
            $snapshot = $machine->initialSnapshot($definition, null, $t0)->getSnapshot();
            $result = $machine->transition($definition, $snapshot, Event::external('GO', $t0));
            $this->assertTrue($result->isAccepted(), 'inState reads the snapshot before the event (' . ($aFirst ? 'a first' : 'b first') . ')');
            $this->assertEquals('a2', $result->getSnapshot()->stateOf('a'));
            $this->assertEquals('b2', $result->getSnapshot()->stateOf('b'));
        }
    }

    private function testUnhandledAndFinalStatesInert(): void
    {
        $definition = Spec::machine('inert', 1)
            ->region('r', 'open')
                ->state('open')->on('CLOSE', 'closed', 'close')
                ->finalState('closed')
            ->region('s', 'idle')
                ->state('idle')->on('FINISH', 'finished', 'finish')
                ->finalState('finished')
            ->build();
        $machine = new StateMachine();
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $snapshot = $machine->initialSnapshot($definition, null, $t0)->getSnapshot();

        $unhandled = $machine->transition($definition, $snapshot, Event::external('NOPE', $t0));
        $this->assertTrue($unhandled->isUnhandled());
        $this->assertFalse($unhandled->isAccepted());
        $this->assertFalse($unhandled->isRejected());
        $this->assertTrue($unhandled->getSnapshot() === $snapshot);
        $this->assertEquals([TransitionResult::CODE_UNHANDLED], $unhandled->getReason()->getCodes());
        $this->assertEquals(FailureSeverity::INFO, $unhandled->getReason()->failures[0]->severity);
        $this->assertEquals("Event 'NOPE' is not handled by the current state(s): r=open, s=idle.", $unhandled->getReason()->getReasons()[0]);
        $this->assertEquals([], $unhandled->getRejections());

        $closed = $machine->transition($definition, $snapshot, Event::external('CLOSE', $t0));
        $this->assertFalse($closed->getSnapshot()->isDone(), 'One region final is not enough');
        $finished = $machine->transition($definition, $closed->getSnapshot(), Event::external('FINISH', $t0));
        $this->assertTrue($finished->getSnapshot()->isDone(), 'Every region final: done');
        $this->assertTrue($machine->transition($definition, $finished->getSnapshot(), Event::external('CLOSE', $t0))->isUnhandled());
        $this->assertEquals([], $machine->nextEvents($definition, $finished->getSnapshot(), null, $t0));
    }

    private function testRegionLevelTransitionsPriority(): void
    {
        $definition = Spec::machine('wildcard', 1)
            ->region('r', 'a')
                ->state('a')->on('NEXT', 'b', 'next')
                ->state('b')
                    ->on('CANCEL', 'special', 'cancel_special')->guard('payloadHas', ['field' => 'special'])
                    ->on('NEXT', 'done', 'finish')
                ->state('special')->on('NEXT', 'done', 'special_done')
                ->finalState('done')
                ->finalState('cancelled')
                ->onAnyState('CANCEL', 'cancelled', 'cancel_any')
            ->build();
        $this->assertEquals([], MachineDefinitionValidator::validate($definition, new GuardRegistry()));
        $machine = new StateMachine();
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $a = $machine->initialSnapshot($definition, null, $t0)->getSnapshot();

        // From any non-final state, the region-level transition applies.
        $fromA = $machine->transition($definition, $a, Event::external('CANCEL', $t0));
        $this->assertEquals('cancel_any', $fromA->getTransitions()[0]->transitionId);
        $this->assertEquals('cancelled', $fromA->getSnapshot()->stateOf('r'));

        // The state's own transition has priority; when its guard fails, the region-level one runs.
        $b = $machine->transition($definition, $a, Event::external('NEXT', $t0))->getSnapshot();
        $special = $machine->transition($definition, $b, Event::external('CANCEL', $t0, ['special' => 'yes']));
        $this->assertEquals('cancel_special', $special->getTransitions()[0]->transitionId);
        $fallback = $machine->transition($definition, $b, Event::external('CANCEL', $t0));
        $this->assertEquals('cancel_any', $fallback->getTransitions()[0]->transitionId);

        // Never from a final state.
        $done = $machine->transition($definition, $b, Event::external('NEXT', $t0))->getSnapshot();
        $this->assertEquals('done', $done->stateOf('r'));
        $this->assertTrue($machine->transition($definition, $done, Event::external('CANCEL', $t0))->isUnhandled());
    }

    private function testInternalEvents(): void
    {
        $definition = Spec::machine('internal', 1)
            ->region('r', 'waiting')
                ->state('waiting')
                    ->on('SYNC_DONE', 'synced', 'sync_done')->internal()
                    ->on('EDIT', null, 'edit')
                ->finalState('synced')
            ->build();
        $this->assertTrue($definition->getTransition('sync_done')->internal);
        $machine = new StateMachine();
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $snapshot = $machine->initialSnapshot($definition, null, $t0)->getSnapshot();

        $outside = $machine->transition($definition, $snapshot, Event::external('SYNC_DONE', $t0));
        $this->assertTrue($outside->isRejected(), 'Internal transition refused from outside');
        $this->assertEquals([TransitionResult::CODE_INTERNAL_EVENT], $outside->getReason()->getCodes());
        $this->assertEquals(FailureSeverity::ERROR, $outside->getReason()->failures[0]->severity);

        $inside = $machine->transition($definition, $snapshot, Event::internal('SYNC_DONE', $t0));
        $this->assertTrue($inside->isAccepted());
        $this->assertEquals('synced', $inside->getSnapshot()->stateOf('r'));
        $this->assertTrue(Event::internal('X', $t0)->isInternal());
        $this->assertEquals(EventOrigin::INTERNAL, Event::internal('X', $t0)->getOrigin());

        // The action panel hides internal-only events unless asked.
        $this->assertEquals(['EDIT'], array_keys($machine->nextEvents($definition, $snapshot, null, $t0)));
        $all = $machine->nextEvents($definition, $snapshot, null, $t0, true);
        $this->assertEquals(['SYNC_DONE', 'EDIT'], array_keys($all));
        $this->assertTrue($all['SYNC_DONE']->isRejected());
    }

    private function testDeadlines(): void
    {
        $definition = Spec::machine('deadline', 1)
            ->region('doc', 'delivered')
                ->state('delivered')->deadline('P3D', 'EXPIRE')->entry('notify_delivered')->exit('leave_delivered')
                    ->on('EXPIRE', 'expired', 'expire')
                    ->on('RESUBMIT', 'delivered', 'resubmit')->action('log_resubmit')
                    ->on('COMMENT', null, 'comment')->action('log_comment')
                    ->on('VALIDATE', 'validated', 'validate')
                ->finalState('validated')
                ->finalState('expired')
            ->build();
        $this->assertEquals([], MachineDefinitionValidator::validate($definition, new GuardRegistry()));
        $machine = new StateMachine();
        $t0 = $this->at('2026-10-10T10:00:00+00:00');

        // Entering the state records dueAt = occurredAt + P3D.
        $start = $machine->initialSnapshot($definition, null, $t0);
        $snapshot = $start->getSnapshot();
        $this->assertEquals('2026-10-13T10:00:00.000000+00:00', $snapshot->dueAt('doc')->format(MachineSnapshot::DATE_FORMAT));
        $this->assertEquals(['entry:doc:notify_delivered'], $this->effectLabels($start->getEffects()));
        $this->assertEquals(null, $start->getEffects()[0]->transitionId);

        // The deadline event is internal and only accepted once due.
        $external = $machine->transition($definition, $snapshot, Event::external('EXPIRE', $t0->modify('+4 days')));
        $this->assertTrue($external->isRejected());
        $this->assertEquals([TransitionResult::CODE_INTERNAL_EVENT], $external->getReason()->getCodes());
        $early = $machine->transition($definition, $snapshot, Event::internal('EXPIRE', $t0->modify('+1 day')));
        $this->assertTrue($early->isRejected());
        $this->assertEquals([TransitionResult::CODE_DEADLINE_NOT_DUE], $early->getReason()->getCodes());
        $this->assertTrue(str_contains($early->getReason()->getReasons()[0], '2026-10-13T10:00:00'), 'The due instant is in the reason');

        // Targetless: only the transition action; state and deadline untouched.
        $comment = $machine->transition($definition, $snapshot, Event::external('COMMENT', $t0->modify('+1 day')));
        $this->assertTrue($comment->isAccepted());
        $this->assertEquals(['transition:doc:log_comment'], $this->effectLabels($comment->getEffects()));
        $this->assertTrue($comment->getTransitions()[0]->targetless);
        $this->assertFalse($comment->getTransitions()[0]->isReentry());
        $this->assertEquals('delivered', $comment->getTransitions()[0]->to);
        $this->assertTrue($comment->getSnapshot()->dueAt('doc') == $snapshot->dueAt('doc'), 'Targetless keeps the deadline');

        // Reentry: exit + transition + entry, deadline recomputed from the new occurredAt.
        $resubmit = $machine->transition($definition, $snapshot, Event::external('RESUBMIT', $t0->modify('+2 days')));
        $this->assertTrue($resubmit->isAccepted());
        $this->assertEquals(
            ['exit:doc:leave_delivered', 'transition:doc:log_resubmit', 'entry:doc:notify_delivered'],
            $this->effectLabels($resubmit->getEffects())
        );
        $this->assertTrue($resubmit->getTransitions()[0]->isReentry());
        $reentered = $resubmit->getSnapshot();
        $this->assertEquals('2026-10-15T10:00:00.000000+00:00', $reentered->dueAt('doc')->format(MachineSnapshot::DATE_FORMAT));

        // After reentry, the old due instant is no longer enough; the new one is.
        $this->assertTrue($machine->transition($definition, $reentered, Event::internal('EXPIRE', $t0->modify('+4 days')))->isRejected());
        $expired = $machine->transition($definition, $reentered, Event::internal('EXPIRE', $t0->modify('+5 days')));
        $this->assertTrue($expired->isAccepted(), 'Due exactly at dueAt is accepted');
        $this->assertEquals('expired', $expired->getSnapshot()->stateOf('doc'));
        $this->assertEquals(null, $expired->getSnapshot()->dueAt('doc'), 'Leaving the state clears dueAt');
        $this->assertTrue($expired->getSnapshot()->isDone());
        $this->assertEquals(['exit:doc:leave_delivered'], $this->effectLabels($expired->getEffects()));

        // A late or repeated delivery is harmless: the state no longer handles it.
        $this->assertTrue($machine->transition($definition, $expired->getSnapshot(), Event::internal('EXPIRE', $t0->modify('+9 days')))->isUnhandled());

        // Leaving by another path also clears it.
        $validated = $machine->transition($definition, $snapshot, Event::external('VALIDATE', $t0->modify('+1 day')));
        $this->assertEquals(null, $validated->getSnapshot()->dueAt('doc'));
    }

    private function testEffectsOrderAndSelfTransitions(): void
    {
        $definition = Spec::machine('effects', 1)
            ->region('r', 'a')
                ->state('a')->exit('a_exit_1')->exit('a_exit_2')->entry('a_entry')
                    ->on('GO', 'b', 'go')->action('t1', ['n' => 1])->action('t2', ['n' => 2])
                ->state('b')->entry('b_entry_1', ['k' => 'v'])->entry('b_entry_2')
            ->build();
        $machine = new StateMachine();
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $snapshot = $machine->initialSnapshot($definition, null, $t0)->getSnapshot();
        $result = $machine->transition($definition, $snapshot, Event::external('GO', $t0));
        $this->assertEquals(
            ['exit:r:a_exit_1', 'exit:r:a_exit_2', 'transition:r:t1', 'transition:r:t2', 'entry:r:b_entry_1', 'entry:r:b_entry_2'],
            $this->effectLabels($result->getEffects())
        );
        $this->assertEquals(['n' => 1], $result->getEffects()[2]->params);
        $this->assertEquals(['k' => 'v'], $result->getEffects()[4]->params);
        $this->assertEquals(EffectPhase::ENTRY, $result->getEffects()[4]->phase);
        $this->assertEquals('go', $result->getEffects()[5]->transitionId);
    }

    private function testGuardExceptionBecomesErrorFailure(): void
    {
        $guards = Spec::guardRegistry()
            ->register('creditBureauUp', static fn(array $p): ISpecification => new PredicateSpecification(
                static fn($candidate): bool => throw new RuntimeException('credit bureau unavailable')
            ))
            ->register('explodingEvaluate', static fn(array $p): ISpecification => new class extends AbstractSpecification {
                public function getType(): string
                {
                    return 'mixed';
                }

                public function isSatisfiedBy(mixed $candidate): bool
                {
                    return true;
                }

                public function evaluate(mixed $candidate): SpecificationResult
                {
                    throw new RuntimeException('evaluate exploded');
                }
            });
        $definition = Spec::machine('errors', 1)
            ->region('r', 'a')
                ->state('a')
                    ->on('CHECK', 'checked', 'check')->guard('creditBureauUp')
                    ->on('CHECK', 'fallback', 'check_fallback')
                    ->on('OTHER', 'checked', 'other')->guard('explodingEvaluate')
                ->state('checked')
                ->state('fallback')
            ->build();
        $machine = new StateMachine($guards);
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $snapshot = $machine->initialSnapshot($definition, null, $t0)->getSnapshot();

        $result = $machine->transition($definition, $snapshot, Event::external('CHECK', $t0));
        $this->assertTrue($result->isRejected(), 'An evaluation error never falls through to a lower-priority transition');
        $this->assertTrue($result->getReason()->isError);
        $failure = $result->getReason()->failures[0];
        $this->assertEquals(SpecificationResult::EVALUATION_ERROR_MESSAGE, $failure->message, 'the guard exception is not repeated in the failure');
        $this->assertEquals('credit bureau unavailable', $result->getReason()->exception->getMessage(), 'it stays on the result, for the log');
        $this->assertEquals(FailureSeverity::ERROR, $failure->severity);
        $this->assertTrue($failure->metadata['evaluation_error'] === true);
        $this->assertEquals('check', $failure->metadata['transition_id']);
        $this->assertCount(1, $result->getReason()->failures);
        $this->assertFalse(str_contains((string) json_encode($result->toArray()), '#0 '), 'No stack trace in the serialized result');

        $other = $machine->transition($definition, $snapshot, Event::external('OTHER', $t0));
        $this->assertTrue($other->isRejected());
        $this->assertTrue($other->getReason()->isError);
        $this->assertEquals('Guard:other', $other->getReason()->failures[0]->ruleName);
        $this->assertEquals(FailureSeverity::ERROR, $other->getReason()->failures[0]->severity);
        $this->assertEquals(SpecificationResult::EVALUATION_ERROR_MESSAGE, $other->getReason()->failures[0]->message);
        $this->assertEquals('evaluate exploded', $other->getReason()->exception->getMessage());

        // An unregistered guard at run time is a programming error (the validator reports it at load).
        $this->assertThrows(UnknownGuardException::class, fn() => (new StateMachine())->transition($definition, $snapshot, Event::external('CHECK', $t0)));
    }

    // ==========================================
    // 4. Validator (D17)
    // ==========================================

    private function testValidatorErrors(): void
    {
        $definition = MachineDefinition::fromArray([
            'key' => 'broken', 'version' => 1,
            'regions' => [
                [
                    'name' => 'main', 'initial' => null,
                    'states' => [
                        ['name' => 'a', 'entry' => ['ghost_entry'], 'transitions' => [
                            ['id' => 'to_nowhere', 'event' => 'GO', 'target' => 'nowhere'],
                            ['id' => 'to_other_region', 'event' => 'JUMP', 'target' => 'x'],
                            ['id' => 'bad_in_state', 'event' => 'CHECK', 'target' => 'a', 'guard' => ['guard' => 'inState', 'params' => ['state' => 'side.zzz']]],
                            ['id' => 'unknown_guard', 'event' => 'G', 'target' => 'a', 'guard' => ['not' => 'ghostGuard']],
                            ['id' => 'bad_params', 'event' => 'P', 'target' => 'a', 'guard' => ['guard' => 'payloadHas']],
                            ['id' => 'ghost_action', 'event' => 'A', 'target' => 'a', 'actions' => ['send_email', 'ghost_action']],
                        ]],
                        ['name' => 'end', 'final' => true, 'transitions' => [
                            ['id' => 'reopen', 'event' => 'REOPEN', 'target' => 'a'],
                        ]],
                    ],
                    'transitions' => [
                        ['id' => 'region_jump', 'event' => 'ANY', 'target' => 'y'],
                    ],
                ],
                [
                    'name' => 'side', 'initial' => 'nope',
                    'states' => [['name' => 'x'], ['name' => 'y']],
                ],
            ],
        ]);

        $report = MachineDefinitionValidator::analyze($definition, new GuardRegistry(), ['send_email']);
        $this->assertFalse($report->isValid());
        $this->assertEquals([
            MachineDefinitionError::KIND_MISSING_INITIAL,
            MachineDefinitionError::KIND_UNKNOWN_ACTION,
            MachineDefinitionError::KIND_UNKNOWN_TARGET,
            MachineDefinitionError::KIND_CROSS_REGION_TARGET,
            MachineDefinitionError::KIND_UNKNOWN_IN_STATE,
            MachineDefinitionError::KIND_UNKNOWN_GUARD,
            MachineDefinitionError::KIND_GUARD_BUILD_FAILED,
            MachineDefinitionError::KIND_UNKNOWN_ACTION,
            MachineDefinitionError::KIND_FINAL_STATE_WITH_EXIT,
            MachineDefinitionError::KIND_CROSS_REGION_TARGET,
            MachineDefinitionError::KIND_UNKNOWN_INITIAL,
        ], $report->errorKinds());

        $cross = $report->errorsOfKind(MachineDefinitionError::KIND_CROSS_REGION_TARGET);
        $this->assertEquals('to_other_region', $cross[0]->transitionId);
        $this->assertEquals('a', $cross[0]->state);
        $this->assertEquals('region_jump', $cross[1]->transitionId);
        $this->assertEquals(null, $cross[1]->state, 'Region-level transition has no state');
        $this->assertTrue(str_contains($cross[1]->message, "belongs to region 'side'"));
        $this->assertEquals('ghost_action', $report->errorsOfKind(MachineDefinitionError::KIND_UNKNOWN_ACTION)[1]->transitionId);
        $this->assertTrue(str_contains($report->errorsOfKind(MachineDefinitionError::KIND_UNKNOWN_ACTION)[0]->message, 'ghost_entry'));

        // validate() throws one exception listing them all.
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => MachineDefinitionValidator::validate($definition, new GuardRegistry(), ['send_email']));
        $this->assertCount(11, $e->getErrors());
        $this->assertTrue(str_contains($e->getMessage(), '11 error(s)'));
        $this->assertTrue(str_contains($e->getMessage(), "machine 'broken' v1"));

        // Without the action list, actions are not checked.
        $this->assertCount(9, MachineDefinitionValidator::analyze($definition, new GuardRegistry())->errors);
    }

    private function testValidatorWarnings(): void
    {
        $guards = Spec::guardRegistry()
            ->register('opaque', static fn(array $p): ISpecification => new PredicateSpecification(static fn($c): bool => true))
            ->register('anyone', static fn(array $p): ISpecification => Spec::alwaysTrue());
        $definition = Spec::machine('warnings', 1)
            ->region('flags', 'off')
                ->state('off')->on('TOGGLE', 'on', 'flag_on')
                ->state('on')->on('TOGGLE', 'off', 'flag_off')
            ->region('main', 'start')
                ->state('start')
                    // Overlapping: both inState('flags.on') (decidable, not disjoint).
                    ->on('ROUTE', 'left', 'route_left')->guard('inState', ['state' => 'flags.on'])
                    ->on('ROUTE', 'right', 'route_right')->guard('inState', ['state' => 'flags.on'])
                    // Disjoint: on vs off of the same region, no warning.
                    ->on('SPLIT', 'left', 'split_left')->guard('inState', ['state' => 'flags.on'])
                    ->on('SPLIT', 'right', 'split_right')->guard('inState', ['state' => 'flags.off'])
                    // Disjoint through negation, no warning.
                    ->on('NEG', 'left', 'neg_left')->guard('inState', ['state' => 'flags.on'])
                    ->on('NEG', 'right', 'neg_right')->guard(GuardExpression::not(GuardExpression::ref('inState', ['state' => 'flags.on'])))
                    // Undecidable (closure): no overlap warning.
                    ->on('OPAQUE', 'left', 'opaque_left')->guard('opaque')
                    ->on('OPAQUE', 'right', 'opaque_right')->guard('opaque')
                    // Shadowed: no guard before another transition for the same event.
                    ->on('SKIP', 'left', 'skip_first')
                    ->on('SKIP', 'right', 'skip_never')->guard('inState', ['state' => 'flags.on'])
                    // Tautology: shadows the next one too.
                    ->on('OPEN', 'left', 'open_anyone')->guard('anyone')
                    ->on('OPEN', 'right', 'open_never')
                ->state('left')->on('BACK', 'start', 'left_back')
                ->state('right')
                ->state('island')->on('BACK', 'start', 'island_back')
                ->finalState('closed')
            ->build();

        $report = MachineDefinitionValidator::analyze($definition, $guards);
        $this->assertTrue($report->isValid(), 'Warnings never refuse the definition');

        $overlaps = $report->warningsOfKind(MachineDefinitionWarning::KIND_OVERLAPPING_GUARDS);
        $this->assertCount(1, $overlaps);
        $this->assertEquals('route_right', $overlaps[0]->transitionId);
        $this->assertEquals('ROUTE', $overlaps[0]->eventType);
        $this->assertTrue(str_contains($overlaps[0]->message, "'route_left'"));

        $shadowed = $report->warningsOfKind(MachineDefinitionWarning::KIND_SHADOWED_TRANSITION);
        $this->assertEquals(['skip_never', 'open_never'], array_map(static fn($w) => $w->transitionId, $shadowed));
        $this->assertTrue(str_contains($shadowed[0]->message, 'has no guard'));
        $this->assertTrue(str_contains($shadowed[1]->message, 'always passes'));

        $tautologies = $report->warningsOfKind(MachineDefinitionWarning::KIND_TAUTOLOGICAL_GUARD);
        $this->assertEquals(['open_anyone'], array_map(static fn($w) => $w->transitionId, $tautologies));

        $unreachable = $report->warningsOfKind(MachineDefinitionWarning::KIND_UNREACHABLE_STATE);
        $this->assertEquals(['island', 'closed'], array_map(static fn($w) => $w->state, $unreachable));

        $deadEnds = $report->warningsOfKind(MachineDefinitionWarning::KIND_DEAD_END_STATE);
        $this->assertEquals(['right'], array_map(static fn($w) => $w->state, $deadEnds), 'Final states are never dead ends');

        $this->assertTrue($report->warnings == MachineDefinitionValidator::validate($definition, $guards), 'validate() returns the same warnings');
        $this->assertTrue(MachineDefinitionValidator::isDecidable((new GuardRegistry())->build('inState', ['state' => 'a.b'])));
        $this->assertFalse(MachineDefinitionValidator::isDecidable(new PredicateSpecification(static fn($c): bool => true)));
        $this->assertTrue(MachineDefinitionValidator::isDecidable(Spec::property('context.amount', Spec::greaterThan(10))));

        // Region-level transitions make every non-final state reachable and give it an exit.
        $wildcard = Spec::machine('reach', 1)
            ->region('r', 'a')
                ->state('a')->on('NEXT', 'b')
                ->state('b')
                ->state('limbo')
                ->finalState('cancelled')
                ->onAnyState('CANCEL', 'cancelled', 'cancel_any')
                ->onAnyState('PARK', 'limbo', 'park_any')
            ->build();
        $this->assertEquals([], MachineDefinitionValidator::validate($wildcard, new GuardRegistry()));
    }

    // ==========================================
    // 5. Purity and determinism (RN-02)
    // ==========================================

    private function testPurityAndDeterminism(): void
    {
        $definition = $this->loadFixture();
        $guards = $this->virttusGuards();
        $machine = new StateMachine($guards);
        $t0 = $this->at('2026-10-10T09:00:00-03:00');
        $snapshot = $this->sendAll($machine, $definition, $machine->initialSnapshot($definition, null, $t0)->getSnapshot(), ['COMPLETE_CALCULATION', 'REQUEST_CLIENT_DOCUMENTS', 'CLIENT_DOCUMENTS_RECEIVED', 'SEND_PROPOSAL', 'REGISTER_PENDING'], $t0);
        $context = (object) ['role' => 'operator', 'amounts' => ['rent' => 2500.0]];
        $events = [
            Event::external('APPROVE_PROPOSAL_QUOTATION', $t0, ['motive' => 'x'], 'key-1'),
            Event::external('RESOLVE_PENDING', $t0),
            Event::external('NOPE', $t0),
        ];

        $definitionBefore = $definition->toJson();
        $fingerprintBefore = $definition->fingerprint();
        $snapshotBefore = json_encode($snapshot->toArray());
        $contextBefore = serialize($context);
        $eventsBefore = array_map(static fn(Event $e) => json_encode($e->toArray()), $events);

        foreach ($events as $event) {
            $reference = json_encode($machine->transition($definition, $snapshot, $event, $context)->toArray());
            $nextReference = json_encode(array_map(static fn(TransitionResult $r) => $r->toArray(), $machine->nextEvents($definition, $snapshot, $context, $t0)));
            $identical = true;
            for ($i = 0; $i < 1000; $i++) {
                $run = json_encode($machine->transition($definition, $snapshot, $event, $context)->toArray());
                $identical = $identical && $run === $reference;
            }
            $this->assertTrue($identical, "1000 runs of {$event->type} produce byte-identical results");
            $this->assertEquals($nextReference, json_encode(array_map(static fn(TransitionResult $r) => $r->toArray(), $machine->nextEvents($definition, $snapshot, $context, $t0))));
            // A fresh interpreter over a freshly loaded definition gives the same bytes.
            $fresh = json_encode((new StateMachine($this->virttusGuards()))->transition($this->loadFixture(), MachineSnapshot::fromArray($snapshot->toArray()), $event, $context)->toArray());
            $this->assertEquals($reference, $fresh, 'Same input, fresh objects: same bytes');
        }

        // Inputs are never mutated.
        $this->assertEquals($definitionBefore, $definition->toJson());
        $this->assertEquals($fingerprintBefore, $definition->fingerprint());
        $this->assertEquals($snapshotBefore, json_encode($snapshot->toArray()));
        $this->assertEquals($contextBefore, serialize($context));
        $this->assertEquals($eventsBefore, array_map(static fn(Event $e) => json_encode($e->toArray()), $events));

        // The validator is pure too.
        $first = MachineDefinitionValidator::analyze($definition, $guards);
        $second = MachineDefinitionValidator::analyze($definition, $guards);
        $this->assertTrue($first == $second, 'Same definition, same report');
        $this->assertEquals($definitionBefore, $definition->toJson());

        // The serialized result shape.
        $array = $machine->transition($definition, $snapshot, $events[0], $context)->toArray();
        $this->assertEquals(['status', 'event', 'snapshot', 'transitions', 'effects', 'reason', 'rejections'], array_keys($array));
        $this->assertEquals('rejected', $array['status']);
        $this->assertEquals('key-1', $array['event']['idempotency_key']);
        $this->assertEquals('external', $array['event']['origin']);
        $this->assertEquals('error', $array['reason']['failures'][0]['severity']);
    }

    private function testSnapshotMustMatchDefinition(): void
    {
        $definition = $this->twoRegionMachine();
        $machine = new StateMachine();
        $t0 = $this->at('2026-10-10T10:00:00+00:00');
        $event = Event::external('GO', $t0);

        $this->assertThrows(InvalidSnapshotException::class, fn() => $machine->transition($definition, new MachineSnapshot('aon', 2, ['a' => 'a1', 'b' => 'b1']), $event));
        $this->assertThrows(InvalidSnapshotException::class, fn() => $machine->transition($definition, new MachineSnapshot('other', 1, ['a' => 'a1', 'b' => 'b1']), $event));
        $this->assertThrows(InvalidSnapshotException::class, fn() => $machine->transition($definition, new MachineSnapshot('aon', 1, ['a' => 'a1']), $event));
        $this->assertThrows(InvalidSnapshotException::class, fn() => $machine->transition($definition, new MachineSnapshot('aon', 1, ['a' => 'a1', 'b' => 'ghost']), $event));
        $this->assertThrows(InvalidSnapshotException::class, fn() => $machine->initialSnapshot(
            MachineDefinition::fromArray(['key' => 'k', 'version' => 1, 'regions' => [['name' => 'r', 'states' => [['name' => 'a']]]]]),
            null,
            $t0
        ));

        // Region order in the snapshot does not matter.
        $reordered = new MachineSnapshot('aon', 1, ['b' => 'b1', 'a' => 'a1']);
        $this->assertTrue($machine->transition($definition, $reordered, Event::external('GO', $t0, ['ok' => 1]))->isAccepted());
    }

    private function testSpecFacade(): void
    {
        $this->assertInstanceOf(MachineBuilder::class, Spec::machine('k'));
        $this->assertInstanceOf(GuardRegistry::class, Spec::guardRegistry());
        $this->assertInstanceOf(StateMachine::class, Spec::stateMachine());
        $guards = Spec::guardRegistry();
        $this->assertTrue(Spec::stateMachine($guards)->getGuardRegistry() === $guards);
        $this->assertTrue(Spec::guardRegistry()->has('inState') && Spec::guardRegistry()->has('payloadHas') && Spec::guardRegistry()->has('eventOrigin'));

        $definition = Spec::machine('facade')->region('r', 'a')->state('a')->on('GO', 'b')->finalState('b')->build();
        $this->assertEquals(1, $definition->version);
        $this->assertEquals([], Spec::validateMachine($definition));
        $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::validateMachine(
            Spec::machine('facade')->region('r', 'a')->state('a')->on('GO', 'zzz')->build()
        ));
        $this->assertInstanceOf(InStateSpecification::class, Spec::guardRegistry()->build('inState', ['state' => 'r.a']));
    }

    // ==========================================
    // README (1.8.0): exemplos 14.1 a 14.4 transcritos (EN/pt-BR); cada comentário "// valor" do
    // bloco é uma asserção aqui.
    // ==========================================

    /** O quote do README 14.2 (reusado em 14.3), como escrito. */
    private function readmeQuote(): MachineDefinition
    {
        $quote = Spec::machine('quote', 1)
            ->region('progress', 'registering')
                ->state('registering')
                    ->on('SEND_PROPOSAL', 'proposal_sent')
                ->state('proposal_sent')
                    ->on('APPROVE', 'approved')
                        ->guard(GuardExpression::not(GuardExpression::ref('inState', ['state' => 'pending.open']))
                            ->because('Resolve the open pending item first.')
                            ->withCode('QUOTE_PENDING_OPEN'))
                    ->on('CANCEL', 'cancelled')
                ->finalState('approved')
                ->finalState('cancelled')
            ->region('pending', 'none')
                ->state('none')
                    ->on('REGISTER_PENDING', 'open')->guard('payloadHas', ['field' => 'motive'])
                ->state('open')
                    ->on('RESOLVE_PENDING', 'none')
            ->build();

        return $quote;
    }

    /** README 14.1: máquina mínima de uma região, guard nomeado e motivo da negação. */
    private function testReadme14_1MinimalMachine(): void
    {
        $guards = Spec::guardRegistry()->register('hasRole', fn(array $params) =>
            Spec::property('context.role', Spec::in(...$params['roles']))
                ->because('Only a manager can approve.')
                ->withCode('ROLE_NOT_ALLOWED'));

        $order = Spec::machine('purchase_order', 1)
            ->region('lifecycle', 'draft')
                ->state('draft')
                    ->on('SUBMIT', 'submitted')
                ->state('submitted')
                    ->on('APPROVE', 'approved')->guard('hasRole', ['roles' => ['manager']])
                    ->on('SEND_BACK', 'draft')->guard('payloadHas', ['field' => 'motive'])   // built-in guard
                ->finalState('approved')
            ->build();

        $this->assertEquals([], Spec::validateMachine($order, $guards));              // [] (throws on errors, returns the warnings)

        $machine  = Spec::stateMachine($guards);
        $now      = new DateTimeImmutable('2026-10-10 09:00'); // the machine never reads the clock
        $snapshot = $machine->initialSnapshot($order, null, $now)->getSnapshot();

        $result = $machine->transition($order, $snapshot, Event::external('SUBMIT', $now));
        $this->assertTrue($result->isAccepted());                               // true
        $snapshot = $result->getSnapshot();
        $this->assertEquals('submitted', $snapshot->stateOf('lifecycle'));      // 'submitted'

        $denied = $machine->transition($order, $snapshot, Event::external('APPROVE', $now), ['role' => 'clerk']);
        $this->assertTrue($denied->isRejected());                               // true: a denial is a result, never an exception
        $this->assertTrue($denied->getSnapshot() === $snapshot);                // true: nothing changed
        $this->assertEquals('ROLE_NOT_ALLOWED', $denied->getReason()->failures[0]->code);
        $this->assertEquals('Only a manager can approve.', $denied->getReason()->failures[0]->message);

        $this->assertTrue($machine->transition($order, $snapshot, Event::external('APPROVE', $now), ['role' => 'manager'])
            ->getSnapshot()->isDone());                                         // true: the region is in a final state

        // Prosa do exemplo: evento sem transição no estado atual é unhandled, sem exceção
        $this->assertTrue($machine->transition($order, $snapshot, Event::external('SUBMIT', $now))->isUnhandled());
    }

    /** README 14.2: duas regiões, a pendência como região paralela (padrão da Virttus). */
    private function testReadme14_2ParallelRegionsPendingFlag(): void
    {
        $now = new DateTimeImmutable('2026-10-10 09:00');
        $quote = $this->readmeQuote();
        $this->assertEquals([], Spec::validateMachine($quote));

        $machine = Spec::stateMachine();
        $state = $machine->initialSnapshot($quote, null, $now)->getSnapshot();
        $state = $machine->transition($quote, $state, Event::external('SEND_PROPOSAL', $now))->getSnapshot();
        $state = $machine->transition($quote, $state, Event::external('REGISTER_PENDING', $now, ['motive' => 'Missing ID']))->getSnapshot();

        $this->assertEquals('proposal_sent', $state->stateOf('progress'));     // registering a pending item moved only its region
        $this->assertTrue($state->isIn('pending.open'));                        // true
        $this->assertEquals('QUOTE_PENDING_OPEN', $machine->transition($quote, $state, Event::external('APPROVE', $now))
            ->getReason()->failures[0]->code);
        $this->assertTrue($machine->transition($quote, $state, Event::external('CANCEL', $now))->isAccepted()); // CANCEL is not blocked

        $state = $machine->transition($quote, $state, Event::external('RESOLVE_PENDING', $now))->getSnapshot();
        $this->assertEquals('proposal_sent', $state->stateOf('progress'));     // back where it was
        $this->assertTrue($machine->transition($quote, $state, Event::external('APPROVE', $now))->isAccepted());

        // Prosa do exemplo: a região sem final (pendência) não segura a máquina aberta; máquina done é inerte
        $approved = $machine->transition($quote, $state, Event::external('APPROVE', $now))->getSnapshot();
        $this->assertTrue($approved->isDone());
        $this->assertTrue($machine->transition($quote, $approved, Event::external('REGISTER_PENDING', $now, ['motive' => 'x']))->isUnhandled());
    }

    /** README 14.3: can() e nextEvents() com o motivo, com a pendência ainda aberta. */
    private function testReadme14_3CanAndNextEvents(): void
    {
        $now = new DateTimeImmutable('2026-10-10 09:00');
        $quote = $this->readmeQuote();
        $machine = Spec::stateMachine();
        $state = $machine->initialSnapshot($quote, null, $now)->getSnapshot();
        $state = $machine->transition($quote, $state, Event::external('SEND_PROPOSAL', $now))->getSnapshot();
        $state = $machine->transition($quote, $state, Event::external('REGISTER_PENDING', $now, ['motive' => 'Missing ID']))->getSnapshot();

        ob_start();
        foreach ($machine->nextEvents($quote, $state, null, $now) as $type => $answer) {
            echo $type, ': ', $answer->isAccepted() ? 'enabled' : 'disabled (' . $answer->getReason()->failures[0]->message . ')', PHP_EOL;
        }
        $printed = (string) ob_get_clean();
        $this->assertEquals(
            "APPROVE: disabled (Resolve the open pending item first.)" . PHP_EOL
            . "CANCEL: enabled" . PHP_EOL
            . "RESOLVE_PENDING: enabled" . PHP_EOL,
            $printed
        );

        $this->assertEquals('QUOTE_PENDING_OPEN', $machine->can($quote, $state, Event::external('APPROVE', $now))
            ->getRejections()['progress']->failures[0]->code);                  // the reason per rejecting region
    }

    /** README 14.4: definição em JSON, ida e volta, fingerprint e avisos do validador. */
    private function testReadme14_4DefinitionAsDataAndValidator(): void
    {
        $requirement = MachineDefinition::fromJson(<<<'JSON'
{
  "key": "document_requirement",
  "version": 1,
  "regions": [{
    "name": "lifecycle",
    "initial": "pending",
    "states": [
      {"name": "pending", "transitions": [
        {"event": "DELIVER", "target": "delivered", "guard": {"guard": "payloadHas", "params": {"field": "file"}}}
      ]},
      {"name": "delivered", "deadline": {"after": "P30D", "event": "EXPIRE"}, "transitions": [
        {"event": "VALIDATE", "target": "validated"},
        {"event": "EXPIRE", "target": "expired"}
      ]},
      {"name": "on_hold"},
      {"name": "validated", "final": true},
      {"name": "expired", "final": true}
    ]
  }]
}
JSON);

        $this->assertTrue(MachineDefinition::fromArray($requirement->toArray()) == $requirement);   // lossless round trip
        $this->assertEquals(1, preg_match('/^[0-9a-f]{64}$/', $requirement->fingerprint()));     // 64 hex characters
        $this->assertEquals($requirement->fingerprint(), MachineDefinition::fromJson($requirement->toJson(false))->fingerprint());
        $this->assertTrue($requirement->getTransition('lifecycle.pending.DELIVER') !== null);  // ids generated as region.state.EVENT

        ob_start();
        foreach (Spec::validateMachine($requirement) as $warning) {
            echo $warning->kind, ': ', $warning->state, PHP_EOL;
        }
        $printed = (string) ob_get_clean();
        $this->assertEquals('unreachable_state: on_hold' . PHP_EOL . 'dead_end_state: on_hold' . PHP_EOL, $printed);

        // Prosa do exemplo: o validador recusa (uma exceção com a lista) um alvo inexistente
        $e = $this->assertThrows(InvalidMachineDefinitionException::class, fn() => Spec::validateMachine(
            Spec::machine('broken', 1)->region('r', 'a')->state('a')->on('GO', 'nowhere')->build()
        ));
        $this->assertEquals([MachineDefinitionError::KIND_UNKNOWN_TARGET], $e->getErrorKinds());
    }
}
