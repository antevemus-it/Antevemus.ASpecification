# Prazos em Tempo Útil

### 14.11. Prazos em Tempo Útil (Módulo 41)

Um prazo do [exemplo 14.5](index.md#145-o-runner-uma-transação-idempotência-outbox-e-prazos-persistidos) conta tempo corrido: `P3D` iniciado numa sexta à tarde vence na segunda à tarde, fim de semana incluído. Desde a 2.7.0 um prazo pode **nomear um calendário** (`deadline('P3D', 'LATE', calendar: 'business')`) e então conta **segundos úteis** desse calendário: os segundos dentro das janelas de trabalho, pulando fins de semana e feriados. A definição continua sendo dado (o nome viaja na forma JSON, `"calendar": "business"`); a aplicação mapeia o nome para um calendário num `DeadlineCalendarRegistry` e o entrega ao núcleo: `new StateMachine($guards, $calendars)`.

Um calendário implementa `IDeadlineCalendar`, três métodos puros (sem relógio, sem banco):

| Método | Responde |
|---|---|
| `advance($from, $workSeconds)` | o instante em que um saldo de segundos úteis se esgota (`null` além do limite de dias do calendário, 3 700 por padrão) |
| `workSecondsBetween($from, $to)` | os segundos úteis inteiros entre dois instantes (o saldo de um prazo pausado) |
| `secondsPerDay()` | quantos segundos úteis vale "um dia" de prazo nesse calendário |

A lib traz três calendários e nenhum feriado:

| Calendário | Conta | Um dia |
|---|---|---|
| `ContinuousCalendar` | todo segundo (tempo corrido) | 86 400 |
| `BusinessDayCalendar($timezone, $holidays, $weekend = [6, 7])` | o dia útil inteiro, da meia-noite local à meia-noite local | 86 400 |
| `WorkingHoursCalendar($timezone, $days, $start, $end, $holidays)` | a janela `$start`–`$end` (`HH:MM`, `24:00` para a meia-noite) dos dias da semana de trabalho | `$end - $start` |

Quais dias são feriado é política da aplicação (uma lista nacional, o calendário bancário, um estado, uma cidade, um time), em geral uma tabela editada por tela: um `HolidaySet` guarda datas avulsas e dias que se repetem todo ano a partir de um ano, e `HolidaySet::fromRows()` o monta a partir das linhas dessa tabela. Abaixo, um sinistro cuja análise dura três dias úteis e pausa enquanto uma pendência está aberta, e uma primeira resposta devida em duas horas úteis do time responsável, cujo horário vem no contexto da instância:

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

// Os feriados são política da aplicação: aqui, linhas da tabela dela
$holidays = HolidaySet::fromRows([
    ['date' => '2026-02-16', 'recurring' => false],   // segunda de Carnaval
    ['date' => '2026-02-17', 'recurring' => false],   // terça de Carnaval
    ['date' => '2026-11-20', 'recurring' => true],    // todo 20 de novembro a partir de 2026
]);
$saoPaulo = new DateTimeZone('America/Sao_Paulo');

$calendars = (new DeadlineCalendarRegistry())
    ->register('business', new BusinessDayCalendar($saoPaulo, $holidays))
    // O horário do time responsável vem no contexto de cada instância
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

// Iniciado numa sexta às 17:00, antes do fim de semana e do Carnaval
$runner->start('claim-7', 'claim', 1, $context, new DateTimeImmutable('2026-02-13T17:00:00-03:00'));
$runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM); // '2026-02-20T17:00:00-03:00': 3 dias úteis, fim de semana e Carnaval pulados
$runner->load('claim-7')->snapshot->dueAt('reply')->format(DATE_ATOM);    // '2026-02-18T09:00:00-03:00': 17:00-18:00 na sexta, 08:00-09:00 na quarta

// Uma pendência aberta na quinta às 17:00 pausa a análise com um dia útil restante
$runner->send('claim-7', Event::external('REGISTER_PENDING', new DateTimeImmutable('2026-02-19T17:00:00-03:00')), $context);
$runner->load('claim-7')->snapshot->pausedRemaining('progress');          // 86400: segundos úteis do calendário

// Resolvida na segunda às 09:00: o fim de semana não contou, a análise vence na terça às 09:00
$runner->send('claim-7', Event::external('RESOLVE_PENDING', new DateTimeImmutable('2026-02-23T09:00:00-03:00')), $context);
$runner->load('claim-7')->snapshot->dueAt('progress')->format(DATE_ATOM); // '2026-02-24T09:00:00-03:00'

// Um calendário nunca conta anos nem meses: recusado quando a definição é montada
try {
    Spec::machine('quote', 1)->region('progress', 'open')->state('open')->deadline('P1M', 'EXPIRED', calendar: 'business');
} catch (InvalidMachineDefinitionException $e) {
    $e->getErrors()[0]->kind;                                              // 'invalid_deadline'
}
```

A tabela de feriados da aplicação, lida com a consulta dela (quais linhas valem é decisão dela):

```php
// A sua tabela de feriados: quais linhas valem (nacional, um estado, uma cidade, ativas) é a sua consulta
$rows = $pdo->query("SELECT holiday_date, recurring FROM holiday WHERE scope = 'national' AND active = 1")->fetchAll(PDO::FETCH_ASSOC);
$holidays = HolidaySet::fromRows($rows, dateField: 'holiday_date');
$holidays->isHoliday('2026-02-16');                                        // true
$holidays->isHoliday('2030-11-20');                                        // true: recorrente a partir de 2026
$holidays->isHoliday('2026-07-09');                                        // false: outra abrangência
```

**A duração.** Com calendário, `after` e `afterFrom` contam segundos úteis: cada dia vale o `secondsPerDay()` do calendário (`P2D` é 2 × 86 400 no `BusinessDayCalendar`, 2 × 36 000 num `WorkingHoursCalendar` de 08:00–18:00), uma semana são sete dias, e horas, minutos e segundos contam como tais (`PT2H` é 7 200 em qualquer calendário). Anos e meses são recusados (um mês de dias úteis é ambíguo): em `after` quando a definição é montada (`invalid_deadline`), numa duração do contexto (`afterFrom`) como fonte inválida, que recusa o passo com `SM_DEADLINE_SOURCE_INVALID`. `atFrom` com calendário é recusado: o instante já é absoluto.

**A pausa.** Um prazo com calendário pausado guarda os segundos úteis restantes (`workSecondsBetween(occurredAt, dueAt)`, ainda um inteiro em `paused_remaining`, na unidade do seu calendário) e retoma com `advance(occurredAt, saldo)`, então uma pausa sobre um fim de semana não o consome. Um prazo sem calendário pausa exatamente como antes (segundos corridos).

**O calendário de uma instância.** Um nome aponta para um calendário, ou para uma fábrica `fn(object|array|null $context): IDeadlineCalendar` que recebe o contexto da chamada (o time responsável, a cidade do contrato). A fábrica tem de devolver o mesmo calendário para a mesma instância todas as vezes (ao entrar, ao pausar e ao retomar), porque o saldo da pausa é medido nele. Uma fábrica que lança exceção recusa o passo com um `ERROR` (`evaluation_error`); uma duração que o calendário não consegue posicionar dentro do seu limite de dias o recusa com `SM_DEADLINE_SOURCE_INVALID`.

**Validação.** `MachineDefinitionValidator::analyze()` e `validate()` recebem o registro como novo último parâmetro e apontam um nome de calendário não registrado (`unknown_calendar`); o runner sempre valida com os calendários do seu núcleo, então uma definição que nomeia um calendário desconhecido nunca inicia.

**O instante de vencimento é gravado.** `dueAt` é calculado quando o estado é alcançado (e quando uma pausa termina) e gravado; o cron encontra os prazos vencidos por esse instante, como antes. Um feriado cadastrado depois do início de um prazo **não** o move: o prazo está materializado, e a lib nunca o recalcula (uma pausa feita depois mede o saldo com o calendário daquele momento). A aplicação que precisa que o feriado novo conte publica e migra, ou reinicia o prazo. O aviso "a vencer" não é um segundo prazo: calcule-o com `workSecondsBetween($now, $dueAt)`.

**Fusos horários.** Datas locais e janelas são montadas no fuso do calendário (nunca somando 86 400 segundos a uma meia-noite), então um dia útil com mudança de horário de verão tem 23 ou 25 horas úteis, como o relógio da parede. `ContinuousCalendar` conta segundos corridos: com ele, uma pausa se comporta exatamente como sem calendário, e `P1D` são 24 horas corridas, enquanto um prazo sem calendário soma um dia de relógio (23 ou 25 horas nesses dois dias do ano).
