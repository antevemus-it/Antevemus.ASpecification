# Deadlines in Business Time

### 14.11. Deadlines in Business Time (Module 41)

A deadline of [example 14.5](index.md#145-the-runner-one-transaction-idempotency-outbox-and-persisted-deadlines) counts elapsed time: `P3D` entered on a Friday afternoon expires on Monday afternoon, weekend included. Since 2.7.0 a deadline can **name a calendar** (`deadline('P3D', 'LATE', calendar: 'business')`) and then counts **working seconds** of that calendar: the seconds inside its working windows, skipping weekends and holidays. The definition stays data (the name travels in its JSON form, `"calendar": "business"`); the application maps the name to a calendar in a `DeadlineCalendarRegistry` and gives it to the core: `new StateMachine($guards, $calendars)`.

A calendar implements `IDeadlineCalendar`, three pure methods (no clock, no database):

| Method | Answers |
|---|---|
| `advance($from, $workSeconds)` | the instant at which a balance of working seconds runs out (`null` beyond the calendar's limit of days, 3 700 by default) |
| `workSecondsBetween($from, $to)` | the whole working seconds between two instants (the balance of a paused deadline) |
| `secondsPerDay()` | how many working seconds "one day" of a deadline is worth in this calendar |

The library ships three calendars and no holiday at all:

| Calendar | Counts | One day |
|---|---|---|
| `ContinuousCalendar` | every second (elapsed time) | 86 400 |
| `BusinessDayCalendar($timezone, $holidays, $weekend = [6, 7])` | business days whole, from local midnight to local midnight | 86 400 |
| `WorkingHoursCalendar($timezone, $days, $start, $end, $holidays)` | the window `$start`–`$end` (`HH:MM`, `24:00` for midnight) of the working weekdays | `$end - $start` |

Which days are holidays is the application's policy (a national list, a bank calendar, a state, a city, a team), usually a table edited by a screen: a `HolidaySet` holds single dates and days that repeat every year from a given year on, and `HolidaySet::fromRows()` builds it from the rows of such a table. Below, a claim whose analysis lasts three business days and pauses while a pending item is open, and a first reply due within two working hours of the team in charge, whose hours come with the context of the instance:

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\Calendar\BusinessDayCalendar;
use Antevemus\ASpecification\StateMachine\Calendar\DeadlineCalendarRegistry;
use Antevemus\ASpecification\StateMachine\Calendar\HolidaySet;
use Antevemus\ASpecification\StateMachine\Calendar\WorkingHoursCalendar;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\StateMachine\StateMachine;

// The holidays are the application's policy: here, rows of its own table
$holidays = HolidaySet::fromRows([
    ['date' => '2026-02-16', 'recurring' => false],   // Carnival Monday
    ['date' => '2026-02-17', 'recurring' => false],   // Carnival Tuesday
    ['date' => '2026-11-20', 'recurring' => true],    // every November 20th from 2026 on
]);
$saoPaulo = new DateTimeZone('America/Sao_Paulo');

$calendars = (new DeadlineCalendarRegistry())
    ->register('business', new BusinessDayCalendar($saoPaulo, $holidays))
    // The working hours of the team in charge come with the context of each instance
    ->register('team', fn(array $context) => new WorkingHoursCalendar($saoPaulo, $context['team']['days'], $context['team']['start'], $context['team']['end'], $holidays));

$claim = Spec::machine('claim', 1)
    ->region('progress', 'analysis')
        ->state('analysis')->deadline('P3D', 'ANALYSIS_OVERDUE', calendar: 'business', pausedWhile: 'pending.open')
            ->on('APPROVE', 'approved')
            ->on('ANALYSIS_OVERDUE', 'late')
        ->state('late')->on('APPROVE', 'approved')
        ->finalState('approved')
    ->region('reply', 'waiting')
        ->state('waiting')->deadline('PT2H', 'FIRST_REPLY_LATE', calendar: 'team')
            ->on('REPLY', 'replied')
            ->on('FIRST_REPLY_LATE', 'replied')
        ->finalState('replied')
    ->region('pending', 'none')
        ->state('none')->on('REGISTER_PENDING', 'open')
        ->state('open')->on('RESOLVE_PENDING', 'none')
    ->build();

$runner = new MachineRunner(new StateMachine(calendars: $calendars), new InMemoryMachineDefinitionRepository([$claim]), MachinePersistence::inMemory());
$context = ['team' => ['days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '18:00']];

// Started on a Friday at 17:00, before the weekend and Carnival
$runner->start('claim-7', 'claim', 1, $context, new DateTimeImmutable('2026-02-13T17:00:00-03:00'));
$runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM); // '2026-02-20T17:00:00-03:00': 3 business days, the weekend and Carnival skipped
$runner->load('claim-7')->snapshot->dueAt('reply')->format(DATE_ATOM);    // '2026-02-18T09:00:00-03:00': 17:00-18:00 on Friday, 08:00-09:00 on Wednesday

// A pending item opened on Thursday at 17:00 pauses the analysis with one business day left
$runner->send('claim-7', Event::external('REGISTER_PENDING', new DateTimeImmutable('2026-02-19T17:00:00-03:00')), $context);
$runner->load('claim-7')->snapshot->pausedRemaining('progress');          // 86400: working seconds of the calendar

// Resolved on Monday at 09:00: the weekend did not count, the analysis is due on Tuesday at 09:00
$runner->send('claim-7', Event::external('RESOLVE_PENDING', new DateTimeImmutable('2026-02-23T09:00:00-03:00')), $context);
$runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM); // '2026-02-24T09:00:00-03:00'

// A calendar never counts years or months: refused when the definition is built
try {
    Spec::machine('quote', 1)->region('progress', 'open')->state('open')->deadline('P1M', 'EXPIRED', calendar: 'business');
} catch (InvalidMachineDefinitionException $e) {
    $e->getErrors()[0]->kind;                                              // 'invalid_deadline'
}
```

The holiday table of the application, read with its own query (which rows apply is its decision):

```php
// Your own holiday table: which rows apply (national, a state, a city, active) is your query
$rows = $pdo->query("SELECT holiday_date, recurring FROM holiday WHERE scope = 'national' AND active = 1")->fetchAll(PDO::FETCH_ASSOC);
$holidays = HolidaySet::fromRows($rows, dateField: 'holiday_date');
$holidays->isHoliday('2026-02-16');                                        // true
$holidays->isHoliday('2030-11-20');                                        // true: recurring from 2026 on
$holidays->isHoliday('2026-07-09');                                        // false: another scope
```

**The duration.** With a calendar, `after` and `afterFrom` count working seconds: each day is the calendar's `secondsPerDay()` (`P2D` is 2 × 86 400 in `BusinessDayCalendar`, 2 × 36 000 in a 08:00–18:00 `WorkingHoursCalendar`), a week is seven days, and hours, minutes and seconds count as such (`PT2H` is 7 200 in any calendar). Years and months are refused (a month of business days is ambiguous): in `after` when the definition is built (`invalid_deadline`), in a context duration (`afterFrom`) as an invalid source, which rejects the step with `SM_DEADLINE_SOURCE_INVALID`. `atFrom` with a calendar is refused: the instant is already absolute.

**The pause.** A paused deadline with a calendar keeps the working seconds left (`workSecondsBetween(occurredAt, dueAt)`, still an integer in `paused_remaining`, in the unit of its calendar) and resumes with `advance(occurredAt, balance)`, so a pause over a weekend does not spend it. A deadline without calendar pauses exactly as before (elapsed seconds).

**The calendar of an instance.** A name maps to one calendar, or to a factory `fn(object|array|null $context): IDeadlineCalendar` that receives the context of the call (the team in charge, the city of the contract). The factory must return the same calendar for the same instance every time (on entering, pausing and resuming), because the balance of a pause is measured in it. A factory that throws rejects the step with an `ERROR` (`evaluation_error`); a duration the calendar cannot place within its limit of days rejects it with `SM_DEADLINE_SOURCE_INVALID`.

**Validation.** `MachineDefinitionValidator::analyze()` and `validate()` take the registry as a new last parameter and report a calendar name that is not registered (`unknown_calendar`); the runner always validates with the calendars of its core, so a definition naming an unknown calendar never starts.

**The due instant is stored.** `dueAt` is computed when the state is entered (and when a pause ends) and stored; the cron finds the due deadlines by that instant, as before. A holiday registered after a deadline started does **not** move it: the deadline is materialized, and the library never recomputes it (a pause taken later measures its balance with the calendar of that moment). An application that needs the new holiday to count publishes and migrates, or restarts the deadline. A warning "due soon" is not a second deadline: compute it with `workSecondsBetween($now, $dueAt)`.

**Time zones.** Local dates and windows are built in the calendar's time zone (never by adding 86 400 seconds to a midnight), so a business day with a daylight-saving change has 23 or 25 working hours, as the clock on the wall. `ContinuousCalendar` counts elapsed seconds: under it, a pause behaves exactly as without calendar, and `P1D` is 24 elapsed hours, while a deadline without calendar adds one wall-clock day (23 or 25 hours on those two days of the year).
