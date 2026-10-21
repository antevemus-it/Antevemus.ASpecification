<?php

declare(strict_types=1);

/**
 * Generates the two fixtures of the real-consumer acceptance of Module 40 (2.7.0), deterministically:
 *
 * 1. virttus_quote.v2.json: virttus_quote.v1.json (the quote machine of the Virttus, regions `progress`
 *    and `pending`) with
 *    - the legacy state ids as state codes, copied from AflowEngineStatesEnum::getId() of
 *      Virttus.RentalGuaranteeManager (app/model/guarantee/domain/workflow/enums): progress 1..19 and 21,
 *      `pending.open` = 20 (PendingRegistered), `pending.none` without code;
 *    - a third region `sla` (idle -> running -> met | breached | stopped): a synthetic policy "proposal
 *      within 2 days of the calculation" in continuous time (P2D), paused while `pending.open`, whose
 *      condition is a `spec` guard over the context (`product = standard`), with a targetless fallback
 *      so the condition never rejects the event for the other regions.
 *
 * 2. virttus_quote.synthetic_history.json: a synthetic sample with the shape of
 *    "aflow-engine".transitions_history (only ids, keys, instants and the unit; no data of a person):
 *    two tenants, quotes walking the legacy graph (the transitions of v1, with the legacy ids of their
 *    states), `from`/`to` as the legacy single-state model records them (`pending_registered` = 20, and
 *    `resolve_pending` back to the state before the pending item), plus one case of each exclusion the
 *    replay must count: a pending resolution before the fix of 2026-08-10 that went back to
 *    `registering`, a chain break and a duplicate in the same second.
 *
 * Run from the repository root (the output is committed; running it again gives the same bytes):
 *
 *   XDEBUG_MODE=off php tests/Fixtures/state_machine/generate_virttus_quote_v2.php
 *
 * @version 2.7.0
 */

$dir = __DIR__;
$v1 = json_decode((string) file_get_contents($dir . '/virttus_quote.v1.json'), true, 512, JSON_THROW_ON_ERROR);

/** AflowEngineStatesEnum::getId() of the Virttus (RejectProposalQuotation has no row: null). */
$legacyIds = [
    'registering' => 1, 'calculation_done' => 2, 'under_review' => 3, 'awaiting_approval' => 4,
    'awaiting_documents' => 5, 'awaiting_proof_of_life' => 6, 'awaiting_signatures' => 7, 'proposal_sent' => 8,
    'completed' => 9, 'awaiting_hiring_proposal' => 10, 'approved' => 11, 'rejected' => 12, 'cancelled' => 13,
    'pending_scoring_analysis' => 14, 'rejected_by_scoring' => 15, 'approved_with_scoring_restriction' => 16,
    'pending_income_adjustment' => 17, 'awaiting_client_documents' => 18, 'approve_proposal_quotation' => 19,
    'pending_registered' => 20, 'policy_restrictions' => 21,
];

// 1. The definition v2 ---------------------------------------------------------------------------------
$v2 = $v1;
$v2['version'] = 2;
foreach ($v2['regions'] as &$region) {
    foreach ($region['states'] as &$state) {
        $name = $region['name'] === 'pending' && $state['name'] === 'open' ? 'pending_registered' : $state['name'];
        if ($region['name'] === 'progress' || $name === 'pending_registered') {
            $state['code'] = $legacyIds[$name];
        }
    }
    unset($state);
}
unset($region);
$v2['regions'][] = [
    'name' => 'sla',
    'initial' => 'idle',
    'states' => [
        [
            'name' => 'idle',
            'transitions' => [
                [
                    'id' => 'sla_start',
                    'event' => 'COMPLETE_CALCULATION',
                    'target' => 'running',
                    'guard' => [
                        'guard' => 'spec',
                        'params' => ['document' => ['v' => 1, 'spec' => ['t' => 'property', 'p' => 'product', 's' => ['t' => 'eq', 'v' => 'standard']]]],
                    ],
                ],
                ['id' => 'sla_not_applicable', 'event' => 'COMPLETE_CALCULATION'],
            ],
        ],
        [
            'name' => 'running',
            'deadline' => [
                'after' => 'P2D',
                'event' => 'SLA_BREACHED',
                'pausedWhile' => ['guard' => 'inState', 'params' => ['state' => 'pending.open']],
            ],
            'transitions' => [
                ['id' => 'sla_met', 'event' => 'SEND_PROPOSAL', 'target' => 'met'],
                ['id' => 'sla_breached', 'event' => 'SLA_BREACHED', 'target' => 'breached'],
                ['id' => 'sla_stopped', 'event' => 'CANCEL', 'target' => 'stopped'],
            ],
        ],
        ['name' => 'met', 'final' => true],
        ['name' => 'breached', 'final' => true],
        ['name' => 'stopped', 'final' => true],
    ],
];
file_put_contents(
    $dir . '/virttus_quote.v2.json',
    json_encode($v2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
);

// 2. The synthetic sample ------------------------------------------------------------------------------
$edges = [];   // legacy state id => list of [transition_key, to id]
$events = [];  // transition_key => event type
foreach ($v1['regions'][0]['states'] as $state) {
    foreach ($state['transitions'] ?? [] as $transition) {
        $edges[$legacyIds[$state['name']]][] = [$transition['id'], $legacyIds[$transition['target']]];
        $events[$transition['id']] = $transition['event'];
    }
}
$finals = [9, 11, 12, 13, 15];
// The main path is preferred, so most quotes reach a proposal (and the SLA has something to measure).
$preferred = ['complete_calculation', 'request_client_documents', 'client_documents_received', 'send_proposal', 'approve_proposal_quotation', 'complete_proposal'];

mt_srand(46027);
$tenants = ['unit-7', 'unit-12'];
$processes = [];
$start = new DateTimeImmutable('2026-09-01T09:00:00-03:00');
for ($p = 1; $p <= 14; $p++) {
    $tenant = $tenants[$p % 2];
    $at = $start->modify('+' . (($p - 1) * 7) . ' hours');
    $products = ['standard', 'standard', 'premium'];
    $process = ['id' => sprintf('quote-%03d', $p), 'tenant' => $tenant, 'product' => $products[$p % 3], 'started_at' => $at->format(DATE_ATOM), 'steps' => []];
    $current = 1;
    $beforePending = null;
    for ($step = 0; $step < 14 && !in_array($current, $finals, true); $step++) {
        $at = $at->modify('+' . mt_rand(2, 30) . ' hours +' . mt_rand(0, 59) . ' minutes');
        if ($current === 20) {
            $process['steps'][] = ['key' => 'resolve_pending', 'at' => $at->format(DATE_ATOM), 'from' => 20, 'to' => $beforePending];
            $current = $beforePending;
            continue;
        }
        if (mt_rand(1, 6) === 1 && $step > 0) {
            $process['steps'][] = ['key' => 'register_pending', 'at' => $at->format(DATE_ATOM), 'from' => $current, 'to' => 20];
            $beforePending = $current;
            $current = 20;
            continue;
        }
        $options = $edges[$current] ?? [];
        if ($options === []) {
            break; // awaiting_signatures has no way out in the legacy graph
        }
        $main = array_values(array_filter($options, static fn(array $e): bool => in_array($e[0], $preferred, true)));
        $pick = $main !== [] && mt_rand(1, 10) <= 8 ? $main[mt_rand(0, count($main) - 1)] : $options[mt_rand(0, count($options) - 1)];
        $process['steps'][] = ['key' => $pick[0], 'at' => $at->format(DATE_ATOM), 'from' => $current, 'to' => $pick[1]];
        $current = $pick[1];
    }
    $processes[] = $process;
}

// One case of each exclusion, on three extra quotes.
$processes[] = [
    'id' => 'quote-901', 'tenant' => 'unit-7', 'product' => 'standard', 'started_at' => '2026-07-01T09:00:00-03:00',
    'steps' => [
        ['key' => 'complete_calculation', 'at' => '2026-07-01T10:00:00-03:00', 'from' => 1, 'to' => 2],
        ['key' => 'register_pending', 'at' => '2026-07-01T11:00:00-03:00', 'from' => 2, 'to' => 20],
        // Before 2026-08-10 the legacy engine sent a resolved pending item back to registering.
        ['key' => 'resolve_pending', 'at' => '2026-07-02T09:00:00-03:00', 'from' => 20, 'to' => 1],
        ['key' => 'complete_calculation', 'at' => '2026-07-02T10:00:00-03:00', 'from' => 1, 'to' => 2],
    ],
];
$processes[] = [
    'id' => 'quote-902', 'tenant' => 'unit-12', 'product' => 'standard', 'started_at' => '2026-09-10T09:00:00-03:00',
    'steps' => [
        ['key' => 'complete_calculation', 'at' => '2026-09-10T10:00:00-03:00', 'from' => 1, 'to' => 2],
        // Chain break: the row says it left awaiting_client_documents, but the quote was in calculation_done.
        ['key' => 'client_documents_received', 'at' => '2026-09-11T10:00:00-03:00', 'from' => 18, 'to' => 10],
        ['key' => 'send_proposal', 'at' => '2026-09-11T11:00:00-03:00', 'from' => 10, 'to' => 8],
    ],
];
$processes[] = [
    'id' => 'quote-903', 'tenant' => 'unit-7', 'product' => 'premium', 'started_at' => '2026-09-12T09:00:00-03:00',
    'steps' => [
        ['key' => 'complete_calculation', 'at' => '2026-09-12T10:00:00-03:00', 'from' => 1, 'to' => 2],
        // Duplicate in the same second (a double click recorded twice).
        ['key' => 'complete_calculation', 'at' => '2026-09-12T10:00:00-03:00', 'from' => 1, 'to' => 2],
        ['key' => 'request_client_documents', 'at' => '2026-09-12T11:00:00-03:00', 'from' => 2, 'to' => 18],
    ],
];

$sample = [
    'description' => 'Synthetic sample with the shape of "aflow-engine".transitions_history of the quote machine (generated by generate_virttus_quote_v2.php; no real data).',
    'legacy_state_ids' => $legacyIds,
    'pending_fix_date' => '2026-08-10T00:00:00-03:00',
    'processes' => $processes,
];
file_put_contents(
    $dir . '/virttus_quote.synthetic_history.json',
    json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
);

echo "virttus_quote.v2.json and virttus_quote.synthetic_history.json written (", count($processes), " quotes).\n";
