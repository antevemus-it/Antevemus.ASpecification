# Ciclo de Vida: Carimbos de Tempo, Exclusão Lógica, Ganchos e o Log de SQL

### 17.1. Ciclo de Vida: Carimbos de Tempo, Exclusão Lógica, Ganchos e o Log de SQL (Módulo 25)

A contabilidade que uma aplicação costuma escrever à mão em volta de uma tabela, como opções do mapeamento e do repositório: carimbos de criação e de alteração, uma remoção que marca a linha em vez de apagá-la, callables em volta de cada escrita, o log de cada comando, booleanos gravados como inteiros ou como texto e o fuso de colunas que guardam hora local sem deslocamento (as tabelas do Adianti, por exemplo). Sem elas nada muda.

```php
use Antevemus\ASpecification\Persistence\InMemoryQueryLogger;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\CreatedAt;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\UpdatedAt;

#[Table('ticket', timezone: 'America/Sao_Paulo', booleanAs: BooleanStorage::Integer)]   // hora local sem deslocamento, flags como 1/0
#[SoftDelete('deleted_at')]                       // remove() marca a linha em vez de apagá-la
class Ticket extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[CreatedAt] public ?DateTimeImmutable $createdAt = null;   // preenchido pelo INSERT, nunca atualizado
    #[UpdatedAt] public ?DateTimeImmutable $updatedAt = null;   // carimbado por toda escrita da linha

    public function __construct(
        #[Column] public string $subject,
        #[Column] public bool $urgent = false,
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

// $pdo do exemplo 17
$pdo->exec('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT, subject VARCHAR(80) NOT NULL,
    urgent SMALLINT NOT NULL, created_at TEXT, updated_at TEXT, deleted_at TEXT)');

$now = new DateTimeImmutable('2026-03-10 14:30:00.750 UTC');
$log = new InMemoryQueryLogger();
$tickets = PdoRepository::forEntity($pdo, Ticket::class)
    ->withClock(function () use (&$now): DateTimeImmutable { return $now; })   // o relógio do sistema por padrão
    ->withQueryLogger($log)                       // valores dos parâmetros ficam de fora, a não ser com logParameters: true
    ->beforePut(function (Ticket $ticket): void { // também afterPut(), beforeRemove(), afterRemove()
        if (trim($ticket->subject) === '') {
            throw new InvalidArgumentException('A ticket needs a subject.');
        }
    });

$ticket = new Ticket('Printer on fire', urgent: true);
$tickets->put($ticket);
$ticket->createdAt->format('Y-m-d H:i:s P');      // '2026-03-10 11:30:00 -03:00': no fuso do mapeamento, ao segundo
$pdo->query('SELECT created_at, urgent FROM ticket')->fetch(PDO::FETCH_NUM);   // ['2026-03-10 11:30:00', 1]
$log->entries()[0]['parameterCount'];             // 5
$log->entries()[0]['parameters'];                 // null: valores podem ser dado pessoal

$now = $now->modify('+2 hours');
$ticket->subject = 'Printer on fire, 3rd floor';
$tickets->put($ticket);
$ticket->updatedAt->format('H:i');                // '13:30' (createdAt continua '11:30')
```

A remoção marca a linha; toda leitura deixa as linhas marcadas de fora, e duas visões as enxergam:

```php
try {
    $tickets->put(new Ticket('  '));
} catch (InvalidArgumentException $e) {
    // o gancho beforePut recusou dentro da transação: nada foi gravado
}

$tickets->remove($ticket);                        // UPDATE ... SET deleted_at: a linha fica
$tickets->count(Spec::alwaysTrue());              // 0: find, iterate, count, findById, select e aggregate a ignoram
$tickets->withTrashed()->count(Spec::alwaysTrue());   // 1: uma visão que também enxerga as linhas marcadas
$tickets->onlyTrashed()->findById(1)->subject;    // 'Printer on fire, 3rd floor'
$tickets->forceRemove($ticket);                   // DELETE: some de verdade
$tickets->withTrashed()->count(Spec::alwaysTrue());   // 0
```

Flags legados gravados como texto, como as colunas `CHAR(1)` `'Y'`/`'N'` das tabelas do Adianti, são lidos de forma estrita:

```php
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\DataIntegrityException;

#[Table('member')]
class Member extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column(booleanAs: BooleanStorage::Char)] public bool $active = true,   // 'Y' / 'N'
        #[Column(booleanAs: BooleanStorage::Char, trueValue: 'S', falseValue: 'N')] public ?bool $admin = null,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE member (id INTEGER PRIMARY KEY, active CHAR(1) NOT NULL, admin CHAR(1))');
$members = PdoRepository::forEntity($pdo, Member::class);
$members->putAll([new Member(1), new Member(2, active: false, admin: true)]);
$pdo->query('SELECT active, admin FROM member WHERE id = 2')->fetch(PDO::FETCH_NUM);   // ['N', 'S']
$members->toWhereClause(Spec::property('active', Spec::isTrue()))->toSql();
// ("active" IS NOT NULL AND "active" IN ('Y', 'y')): toda forma de caixa que a leitura aceita

$pdo->exec("UPDATE member SET active = 'X' WHERE id = 1");   // um valor que o mapeamento não declara
try {
    $members->findById(1);
} catch (DataIntegrityException $e) {
    $e->getStoredValue();                         // 'X': erro de integridade, nunca lido como false
}
```

- **Fusos.** Uma coluna temporal é gravada e lida no fuso que ela declara (`#[Column(timezone: ...)]`), senão no padrão do mapeamento (`#[Table(timezone: ...)]`, `EntityMapping::timezone()`), senão no padrão do repositório (`withTimezone()`), senão em UTC.
- **Carimbos.** `#[CreatedAt]` é preenchido pelo `INSERT` quando a entidade não traz valor (um valor importado é mantido) e nunca é atualizado; `#[UpdatedAt]` é carimbado pelo `INSERT`, por todo `UPDATE` e pela remoção lógica. O instante vem do relógio do repositório (`withClock()`, lido uma vez por chamada) e é truncado ao segundo, então a entidade guarda exatamente o que qualquer coluna de data e hora guarda.
- **Exclusão lógica.** `#[SoftDelete('deleted_at')]` na classe (só a coluna; acrescente `property:` para hidratá-la) ou numa propriedade de data anulável. `remove()` e `removeBy()` marcam só linhas vivas e incrementam o contador `#[Version]` como qualquer escrita; `withTrashed()` e `onlyTrashed()` devolvem visões (cópias do repositório); `forceRemove()` apaga. Uma linha marcada está removida para todo leitor: o `TriggeringRepository` publica a saída e o `TriggerSweeper` a vê sumir. `put()` de uma entidade cuja linha está marcada se comporta como depois de um `DELETE`: uma cópia versionada lança `OptimisticLockException`, uma sem versão é gravada de volta e volta a ser visível. O `toWhereClause()` não mostra a condição da exclusão lógica.
- **Ganchos** pertencem ao repositório (a entidade continua um objeto simples) e rodam dentro da transação da escrita: uma exceção num gancho `before*` ou `after*` desfaz a escrita e se propaga. O `beforePut` roda antes de o identificador ser gerado e de os carimbos serem preenchidos. Com um gancho de remoção, o `removeBy()` carrega as entidades que casam e as remove uma a uma, então um gancho nunca é contornado; sem gancho continua um comando só.
- **Log de SQL.** `IQueryLogger::log(string $sql, int $parameterCount, float $milliseconds, ?array $parameters)` recebe cada comando depois que ele rodou ou falhou; os valores só são passados com `withQueryLogger($logger, logParameters: true)`. Uma exceção lançada pelo logger é ignorada. O `InMemoryQueryLogger` guarda no máximo 10 000 entradas por padrão (`entries()`, `statements()`, `clear()`).
- **Booleanos.** `BooleanStorage::Integer`, por coluna (`#[Column(booleanAs: ...)]`) ou como padrão do mapeamento, grava 1/0 em todo driver, PostgreSQL inclusive (os flags `SMALLINT` de esquemas legados), e uma especificação compila para `"urgent" = 1`; a leitura aceita as duas formas. `BooleanStorage::Char` grava texto, `'Y'`/`'N'` a menos que `trueValue:`/`falseValue:` digam outro (na coluna, ou em `#[Table]`/`EntityMapping::booleanAs()` como padrão; de 1 a 16 letras ou dígitos, diferentes em qualquer caixa). Lê os dois textos em qualquer caixa (e sem os espaços com que uma coluna `CHAR` completa o valor) e `NULL` como `null`; qualquer outro valor gravado lança `DataIntegrityException` com a coluna e o valor. Uma comparação compila para toda forma de caixa do texto (`IN ('Y', 'y')`; `LOWER(col) = 'false'` para um texto de mais de duas letras), então o banco acha exatamente as linhas que a leitura aceita, e a semântica de nulos da 1.11.0 continua valendo. `Native` continua o padrão.
- **`extract()` e `hydrate()`.** `EntityMapping::extract($entity)` dá a linha de uma entidade (coluna => valor da propriedade) e `hydrate($row)` monta uma entidade a partir de uma linha sem chamar o construtor, ambos sem banco: o `toArray()`/`fromArray()` de um active record.
