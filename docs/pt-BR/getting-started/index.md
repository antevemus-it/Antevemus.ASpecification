# Primeiros Passos

## 📋 Requisitos

- **PHP**: `^8.2` (testado no PHP 8.2 e 8.4). O PHP 8.4 só é exigido pela integração opcional com o ALinq (`Antevemus.AlinqCollection`, Módulo 14), detectada em tempo de execução.
- **Extensões PHP**:
  - `ext-json` (para serialização JSON; obrigatória)
  - `ext-mbstring` (obrigatória desde a v1.5.0: especificações de string case-insensitive com Unicode, `mb_strtolower`)
  - `ext-intl` *(opcional: `TextComparison::IgnoreCaseAndAccents`, a comparação de texto que também ignora acentos, exemplos 20 e 20.1; sem ela essa opção é recusada com `MissingExtensionException` e todo o resto funciona)*
  - `ext-sysvsem` *(opcional: `SysVSemaphoreSynchronizer`, o lock leitores/escritor compartilhado entre processos por semáforos SysV IPC, Linux/Unix)*
  - `ext-curl` *(opcional: `HttpDataSource`, a busca HTTP concorrente do `DataResolver`, Módulo 21; sem ela uma fonte HTTP é recusada ao ser registrada e o `CallableDataSource` continua funcionando)*
  - `ext-pdo` *(opcional, com o driver do seu banco: `PdoRepository` (Módulos 22, 25 e 26), os stores PDO da máquina de estados, o `PdoRuleCatalog` e a outbox PDO e o store PDO de estado dos gatilhos de eventos de domínio (Módulos 23 e 24))*
- **Dependência**: uma, do mesmo autor, sem dependências próprias: [`antevemus/adeep-property-access`](https://github.com/antevemus-it/Antevemus.ADeepPropertyAccess) `^1.0` (desde a v2.0.0), o acessor de propriedades compartilhado com o ALinq, para que as duas bibliotecas leiam o mesmo valor do mesmo candidato (exemplo 10.1). O Composer a instala.
- **Pacote opcional**: `antevemus/alinq-collection` `^2.2.0` para `ALinqBridge` (retornos tipados `IALinqCollection`/`IALinqLazyCollection`), `ALinqSpecificationVisitor` e streaming lazy O(1) sobre `IRepository::iterate()` (exige PHP 8.4; o ALinq só lê propriedade não pública marcada com `#[Specifiable]`, a mesma regra deste pacote, e a 2.0 dele resolve propriedades pelo mesmo acessor compartilhado).

> [!NOTE]
> **Vindo da 1.x?** Leia antes o [UPGRADE-2.0.pt-BR.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/UPGRADE-2.0.pt-BR.md): arquivos `.bin` do serializer nativo e catálogos de regras com valores em português são convertidos na 1.14.x, antes da atualização.

## 🚀 Instalação

```bash
composer require antevemus/aspecification
```

## Primeiro Uso

Uma regra é um objeto: componha, pergunte se um candidato a satisfaz, receba todas as falhas com código e mensagem sem exceção e compile a mesma regra para SQL. Depois do `composer require`, com o autoloader do Composer carregado:

```php
use Antevemus\ASpecification\Spec;

// Duas regras de negócio, cada uma com a sua mensagem e o seu código
$adult = Spec::property('age', Spec::greaterThanOrEqualTo(18))
    ->because('Customer must be of legal age.')
    ->withCode('CLI_001');
$active = Spec::property('status', Spec::equalTo('ACTIVE'))
    ->because('Only active accounts can receive a credit line.')
    ->withCode('CLI_002');
$creditRule = $adult->and($active);

$customer = (object) ['age' => 17, 'status' => 'ACTIVE'];   // qualquer objeto: uma entidade, um DTO, um stdClass

$creditRule->isSatisfiedBy($customer);                      // false

$result = $creditRule->evaluate($customer);                 // sem exceção: um resultado com todas as falhas
count($result->failures);                                   // 1
$result->failures[0]->code;                                 // 'CLI_001'
$result->failures[0]->message;                              // 'Customer must be of legal age.'

// A mesma regra como cláusula WHERE parametrizada (15 drivers, 10 dialetos SQL)
$where = Spec::toSql($creditRule, 'pgsql');
$where->getSql();         // '(("age" IS NOT NULL AND "age" >= :p1) AND ("status" IS NOT NULL AND "status" = :p2))'
$where->getParameters();  // [':p1' => 18, ':p2' => 'ACTIVE']
```

Próximos passos: o [notification pattern](../specifications/notification-pattern.md) (o que mostrar quando uma regra não pode ser avaliada), o [fluent chaining](../specifications/fluent-chaining.md) (regras escritas como frases) e o [SQL Query Visitor](../sql/query-visitor.md) (todos os dialetos).
