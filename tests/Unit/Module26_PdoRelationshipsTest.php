<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\DomainEvents\InMemoryDomainEventPublisher;
use Antevemus\ASpecification\DomainEvents\SpecificationTrigger;
use Antevemus\ASpecification\DomainEvents\State\InMemoryTriggerStateStore;
use Antevemus\ASpecification\DomainEvents\TriggeringRepository;
use Antevemus\ASpecification\DomainEvents\TriggerSet;
use Antevemus\ASpecification\DomainEvents\TriggerSweeper;
use Antevemus\ASpecification\Persistence\InMemoryQueryLogger;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\EntityMappingException;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\OptimisticLockException;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\PdoRepositoryException;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToOne;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\RelationMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Tests\Support\PdoCursorTrackingPdo;
use Antevemus\ASpecification\Tests\Support\PdoShopCustomer;
use Antevemus\ASpecification\Tests\Support\PdoShopInvoice;
use Antevemus\ASpecification\Tests\Support\PdoShopInvoiceLine;
use Antevemus\ASpecification\Tests\Support\PdoShopInvoicePayment;
use Antevemus\ASpecification\Tests\Support\PdoShopOrder;
use Antevemus\ASpecification\Tests\Support\PdoShopOrderItem;
use Antevemus\ASpecification\Tests\Support\PdoShopPost;
use Antevemus\ASpecification\Tests\Support\PdoShopProduct;
use Antevemus\ASpecification\Tests\Support\PdoShopTag;
use Antevemus\ASpecification\Tests\Support\ReadmeArticle;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\Support\ReadmeCustomer;
use Antevemus\ASpecification\Tests\Support\ReadmeOrder;
use Antevemus\ASpecification\Tests\Support\ReadmeOrderLine;
use Antevemus\ASpecification\Tests\Support\ReadmeTag;
use Antevemus\ASpecification\Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Module26BadReference - a reference that is not nullable (refused by the mapping).
 *
 * @version 1.13.0
 */
#[Table('aspec_rel_order')]
final class Module26BadReference extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    #[Column('id')]
    public ?int $id = null;

    #[Column('customer_id')]
    public ?int $customerId = null;

    #[ManyToOne(PdoShopCustomer::class, 'customer_id')]
    public PdoShopCustomer $customer;

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

/**
 * Module26UnmappedJoin - a reference whose join column is not mapped to a property (refused).
 *
 * @version 1.13.0
 */
#[Table('aspec_rel_order')]
final class Module26UnmappedJoin extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    #[Column('id')]
    public ?int $id = null;

    #[ManyToOne(PdoShopCustomer::class, 'customer_id')]
    public ?PdoShopCustomer $customer = null;

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

/**
 * Módulo 26 (forward 031, E3, v1.13.0, D7): relacionamentos do PdoRepository.
 *
 * - Mapeamento: #[ManyToOne]/#[OneToMany] e o builder (manyToOne()/oneToMany()) dão o mesmo
 *   mapeamento (equals()); recusas (referência não anulável, coluna de junção sem propriedade, coleção
 *   que não é array, propriedade que é coluna e relação, alvo que não é entidade); a relação não é
 *   coluna nem lida por especificação.
 * - Carga ansiosa: with('items', 'customer', 'items.product', 'orders.items.product') em lote, UMA
 *   consulta por relação e lote (contada pelo log), escopo de soft delete do repositório relacionado,
 *   identidade compartilhada da referência, referência de volta do filho, estado "carregado" fora da
 *   entidade (isLoaded()), iterate() por lote de withBatchSize(), findSingle/findPage/findById.
 * - Referência (ManyToOne): nunca em cascata; chave estrangeira segue o objeto; null só limpa quando
 *   foi carregada não nula; objeto sem id recusado; filtro por whereRelation()/inQuery, SQL = memória.
 * - Composição (OneToMany cascade): upsert dos filhos, remoção de órfãos só em coleção carregada (e só
 *   dos filhos carregados com o pai: um filho adicionado depois por outro não é tocado), coleção não
 *   carregada nunca é vazia, filho movido entre pais na mesma chamada, insertAll em cascata, afterPut
 *   depois dos filhos.
 * - Remoção em cascata: pai sem soft delete apaga os filhos (inclusive os marcados); pai com soft
 *   delete marca os filhos com soft delete e mantém os sem; forceRemove apaga tudo; removeBy idem.
 * - Transação: falha de um filho (CHECK) ou versão velha do pai desfaz tudo.
 * - Eventos: filho decorado por TriggeringRepository publica entrada/saída da cascata e dos órfãos;
 *   filho sem decorador não publica, e o TriggerSweeper percebe a saída.
 * - RN-03: 1 000 pais × 10 filhos = número fixo de consultas × laço ingênuo por pai (impresso).
 * - RN-04: PostgreSQL 17 e MySQL 8.4 com ASPEC_PDO_PGSQL_DSN / ASPEC_PDO_MYSQL_DSN.
 *
 * Suíte própria (e não mais uma etapa do Módulo 25) porque traz o seu esquema (relations.*.sql, com
 * chaves estrangeiras de verdade, que provam a ordem filho-antes-do-pai) e as suas entidades, e o
 * Módulo 25, já com 1 700 linhas, segue provando E1/E2 sem mudar.
 *
 * 1.14.0 (forward 032), aqui porque usa o mesmo esquema e as mesmas lojas (posts e etiquetas entram em
 * relations.*.sql com chave estrangeira na junção):
 * - D1 ManyToMany: atributo = builder e recusas (tabela/colunas que não são identificadores, colunas
 *   iguais, propriedade que não é array, outra conexão); vínculos de donos novos; with('tags') em 3
 *   consultas; sincronização da coleção carregada (entra/sai vínculo, alvo nunca escrito nem apagado,
 *   nenhum comando de escrita na tabela do alvo); coleção não carregada nunca toca a junção; alvo sem id
 *   ou de outra classe recusado sem gravar o dono; alvo com soft delete fora da coleção e vínculo
 *   mantido; whereRelation() pela junção, memória = SQL (com NOT e OR); insertAll(); soft delete do dono
 *   mantém vínculos, remoção física (remove, forceRemove, removeBy em um comando e com gancho) apaga;
 *   rollback desfaz os vínculos.
 * - D2 markLoaded()/markUnloaded(): sem marcar nada é removido; declarada inteira, o que sai é órfão; o
 *   PERIGO documentado (coleção incompleta apaga o que falta, inclusive o acrescentado por outro);
 *   depois da gravação volta ao "carregado" comum; ManyToMany declarada (vínculo de alvo marcado fica);
 *   markUnloaded() não remove nem toca a junção; erros claros.
 * - D3 iterate() com relações por chave: = find() em páginas de 1, 2, 3 e 100, com filtro em SQL e em
 *   PHP, soft delete, ManyToMany; SQLite rastreado (PdoCursorTrackingPdo) prova que nenhum comando roda
 *   com cursor aberto (controle positivo incluído); MySQL com PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false
 *   (controle positivo: sem a paginação o MySQL recusa); sem relações continua um cursor só.
 * - RN-02: with('tags') em 1 000 donos × 5 alvos = 3 consultas; pico de memória de iterate() com
 *   relações em 2 000 e 10 000 linhas no mesmo patamar, contra find() (impressos).
 *
 * @version    1.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module26_PdoRelationshipsTest extends TestCase
{
    private const SCHEMA_DIR = __DIR__ . '/../Fixtures/pdo_repository';

    public function run(): void
    {
        $this->testMapping();
        $this->testReadme173BlocksAreTheSameInBothLanguages();

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 26 roda só os testes de mapeamento.\n");
            return;
        }

        $this->runPortableSuite($this->sqlite(), 'sqlite');
        $this->testRegistry();
        $this->testEagerBenchmark($this->sqlite(), 'sqlite');
        $this->testNoOpenCursorWhileLoading();
        $this->testManyToManyBenchmark($this->sqlite(), 'sqlite');
        $this->testIterateMemory($this->sqlite(), 'sqlite');
        $this->testReadme173RunsAsWritten();

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; RN-04 em {$label} pulado.\n");
                continue;
            }
            $this->loadSchema($pdo, $family);
            $this->runPortableSuite($pdo, $family);
            $this->testEagerBenchmark($pdo, $family);
            $this->testManyToManyBenchmark($pdo, $family);
            $this->testIterateMemory($pdo, $family);
            if ($family === 'mysql') {
                $this->testIterateUnbufferedMySql();
            }
            fwrite(STDOUT, "    [RN-04] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": suíte de relacionamentos do 031/E3 e do 032 OK\n");
        }
    }

    private function runPortableSuite(PDO $pdo, string $family): void
    {
        $this->testEagerLoading($pdo, $family);
        $this->testReferences($pdo, $family);
        $this->testFilterByRelation($pdo, $family);
        $this->testComposition($pdo, $family);
        $this->testCascadeRemoval($pdo, $family);
        $this->testRollback($pdo, $family);
        $this->testDomainEvents($pdo, $family);
        // 1.14.0 (forward 032)
        $this->testManyToMany($pdo, $family);
        $this->testMarkLoaded($pdo, $family);
        $this->testIterateWithRelations($pdo, $family);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Mapeamento
    ///////////////////////////////////////////////////////////////////////////

    private function testMapping(): void
    {
        $attributes = EntityMapping::fromAttributes(PdoShopOrder::class);
        $builder = EntityMapping::for(PdoShopOrder::class)
            ->table('aspec_rel_order')
            ->id('id', IdStrategy::Auto)
            ->column('number')
            ->column('customerId', 'customer_id')
            ->version('version')
            ->oneToMany('items', PdoShopOrderItem::class, 'order', cascade: true)
            ->manyToOne('customer', PdoShopCustomer::class, 'customer_id');
        $this->assertTrue($attributes->equals($builder), 'atributos = builder (relações comparadas por describe(), em qualquer ordem)');
        $notCascaded = EntityMapping::for(PdoShopOrder::class)
            ->table('aspec_rel_order')->id('id', IdStrategy::Auto)->column('number')->column('customerId', 'customer_id')->version('version')
            ->oneToMany('items', PdoShopOrderItem::class, 'order')
            ->manyToOne('customer', PdoShopCustomer::class, 'customer_id');
        $this->assertFalse($attributes->equals($notCascaded), 'cascade faz parte do mapeamento');

        $items = $attributes->getRelation('items');
        $this->assertTrue($items instanceof RelationMapping && $items->isOneToMany() && $items->isComposition() && $items->mappedBy === 'order');
        $customer = $attributes->getRelation('customer');
        $this->assertTrue($customer instanceof RelationMapping && $customer->isManyToOne() && !$customer->isComposition());
        $this->assertEquals('customerId', $attributes->getJoinColumn('customer')->property, 'a coluna de junção é a da propriedade customerId');
        $this->assertEquals(['id', 'number', 'customerId', 'version'], array_keys($attributes->getColumns()), 'relação não é coluna');
        $this->assertFalse(array_key_exists('items', $attributes->extract(new PdoShopOrder('x'))), 'nem entra no extract()');
        $reason = null;
        $this->assertTrue($attributes->findQueryColumn('items', $reason) === null && str_contains((string) $reason, 'whereRelation'), 'nem numa especificação: ' . $reason);
        $this->assertFalse(EntityMapping::fromAttributes(PdoShopCustomer::class)->getRelation('orders')?->isComposition() ?? true, 'OneToMany sem cascade: só leitura');

        // hydrate() dá às relações o valor "não carregado"
        $hydrated = $attributes->hydrate(['id' => 1, 'number' => 'n']);
        $this->assertTrue($hydrated instanceof PdoShopOrder && $hydrated->items === [] && $hydrated->customer === null);
        $this->assertFalse($attributes->isRelationLoaded($hydrated, 'items'), 'e o mapeamento sabe que não foi carregada');

        // Builder das notas fiscais (classes sem atributos)
        $invoice = $this->invoiceMapping();
        $this->assertEquals(['lines', 'payments'], array_keys($invoice->getRelations()));
        $this->assertTrue($invoice->getRelation('lines')?->mappedBy === 'invoiceCode');

        // Recusas
        $this->assertThrows(EntityMappingException::class, fn() => EntityMapping::fromAttributes(Module26BadReference::class), 'referência não anulável');
        $e = $this->assertThrows(EntityMappingException::class, fn() => EntityMapping::fromAttributes(Module26UnmappedJoin::class), 'coluna de junção sem propriedade');
        $this->assertTrue(str_contains($e->getMessage(), 'customer_id') && str_contains($e->getMessage(), 'not mapped to a property'), $e->getMessage());
        $base = EntityMapping::for(PdoShopOrder::class)->table('aspec_rel_order')->id('id', IdStrategy::Auto)->column('customerId', 'customer_id');
        $this->assertThrows(EntityMappingException::class, fn() => $base->oneToMany('number', PdoShopOrderItem::class, 'order'), 'coleção que não é array');
        $this->assertThrows(EntityMappingException::class, fn() => $base->manyToOne('customerId', PdoShopCustomer::class, 'customer_id'), 'propriedade já é coluna');
        $this->assertThrows(EntityMappingException::class, fn() => $base->manyToOne('customer', PdoShopProduct::class, 'customer_id'), 'tipo da propriedade não aceita o alvo');
        $this->assertThrows(EntityMappingException::class, fn() => $base->oneToMany('items', \stdClass::class, 'order'), 'alvo que não é entidade');
        $this->assertThrows(EntityMappingException::class, fn() => $base->oneToMany('items', PdoShopOrderItem::class, 'order-id'), 'mappedBy inválido');
        $this->assertThrows(EntityMappingException::class, fn() => $base->manyToOne('customer', PdoShopCustomer::class, 'customer id'), 'coluna de junção inválida');
        $related = $base->manyToOne('customer', PdoShopCustomer::class, 'customer_id');
        $this->assertThrows(EntityMappingException::class, fn() => $related->column('customer'), 'relação não vira coluna');
        $this->assertThrows(EntityMappingException::class, fn() => $related->manyToOne('customer', PdoShopCustomer::class, 'customer_id'), 'relação mapeada duas vezes');
        $this->assertThrows(EntityMappingException::class, fn() => $base->manyToOne('customer', PdoShopCustomer::class, 'other_id')->assertComplete(), 'junção numa coluna não mapeada');

        // 1.14.0 (forward 032, D1): #[ManyToMany] = manyToMany()
        $posts = EntityMapping::fromAttributes(PdoShopPost::class);
        $postsBuilder = EntityMapping::for(PdoShopPost::class)->table('aspec_rel_post')->id('id', IdStrategy::Auto)->column('title')->softDelete('deleted_at')
            ->manyToMany('tags', PdoShopTag::class, 'aspec_rel_post_tag', 'post_id', 'tag_id');
        $this->assertTrue($posts->equals($postsBuilder), 'ManyToMany: atributos = builder');
        $tags = $posts->getRelation('tags');
        $this->assertTrue($tags instanceof RelationMapping && $tags->isManyToMany() && $tags->isCollection() && !$tags->isComposition() && !$tags->isOneToMany());
        $this->assertEquals(['aspec_rel_post_tag', 'post_id', 'tag_id'], [$tags->joinTable, $tags->joinColumn, $tags->inverseJoinColumn]);
        $this->assertFalse($posts->equals(EntityMapping::for(PdoShopPost::class)->table('aspec_rel_post')->id('id', IdStrategy::Auto)->column('title')->softDelete('deleted_at')
            ->manyToMany('tags', PdoShopTag::class, 'aspec_rel_post_tag', 'tag_id', 'post_id')), 'as colunas da junção fazem parte do mapeamento');
        $this->assertEquals(['id', 'title'], array_keys($posts->getColumns()), 'associação não é coluna');
        $hydratedPost = $posts->hydrate(['id' => 1, 'title' => 't']);
        $this->assertTrue($hydratedPost instanceof PdoShopPost && $hydratedPost->tags === [] && !$posts->isRelationLoaded($hydratedPost, 'tags'), 'hydrate(): associação não carregada, []');
        $reason = null;
        $this->assertTrue($posts->findQueryColumn('tags', $reason) === null && str_contains((string) $reason, 'whereRelation'));
        $postBase = EntityMapping::for(PdoShopPost::class)->table('aspec_rel_post')->id('id', IdStrategy::Auto);
        foreach ([
            'tabela inválida' => fn() => $postBase->manyToMany('tags', PdoShopTag::class, 'post tag', 'post_id', 'tag_id'),
            'tabela com injeção' => fn() => $postBase->manyToMany('tags', PdoShopTag::class, 'post_tag; DROP TABLE x', 'post_id', 'tag_id'),
            'coluna inválida' => fn() => $postBase->manyToMany('tags', PdoShopTag::class, 'post_tag', 'post-id', 'tag_id'),
            'coluna inversa inválida' => fn() => $postBase->manyToMany('tags', PdoShopTag::class, 'post_tag', 'post_id', '"tag_id"'),
            'colunas iguais' => fn() => $postBase->manyToMany('tags', PdoShopTag::class, 'post_tag', 'post_id', 'POST_ID'),
            'propriedade que não é array' => fn() => $postBase->manyToMany('title', PdoShopTag::class, 'post_tag', 'post_id', 'tag_id'),
            'alvo que não é entidade' => fn() => $postBase->manyToMany('tags', \stdClass::class, 'post_tag', 'post_id', 'tag_id'),
            'propriedade que é coluna' => fn() => $postBase->column('title')->manyToMany('title', PdoShopTag::class, 'post_tag', 'post_id', 'tag_id'),
        ] as $label => $refused) {
            $this->assertThrows(EntityMappingException::class, $refused, "ManyToMany recusado: {$label}");
        }
        $this->assertTrue($postBase->manyToMany('tags', PdoShopTag::class, 'blog.post_tag', 'post_id', 'tag_id')->getRelation('tags')?->joinTable === 'blog.post_tag', 'tabela de junção com esquema');
    }

    private function invoiceMapping(): EntityMapping
    {
        return EntityMapping::for(PdoShopInvoice::class)
            ->table('aspec_rel_invoice')
            ->id('code', IdStrategy::Assigned)
            ->column('customerName')
            ->softDelete('deleted_at')
            ->oneToMany('lines', PdoShopInvoiceLine::class, 'invoiceCode', cascade: true)
            ->oneToMany('payments', PdoShopInvoicePayment::class, 'invoiceCode', cascade: true);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Repositórios e dados
    ///////////////////////////////////////////////////////////////////////////

    /**
     * @return object{customers: PdoRepository, products: PdoRepository, orders: PdoRepository, items: PdoRepository, invoices: PdoRepository, lines: PdoRepository, payments: PdoRepository, tags: PdoRepository, posts: PdoRepository}
     */
    private function shop(PDO $pdo, ?InMemoryQueryLogger $log = null): object
    {
        $customers = PdoRepository::forEntity($pdo, PdoShopCustomer::class);
        $products = PdoRepository::forEntity($pdo, PdoShopProduct::class);
        $orders = PdoRepository::forEntity($pdo, PdoShopOrder::class);
        $items = PdoRepository::forEntity($pdo, PdoShopOrderItem::class);
        $invoices = new PdoRepository($pdo, $this->invoiceMapping());
        $lines = new PdoRepository($pdo, EntityMapping::for(PdoShopInvoiceLine::class)->table('aspec_rel_invoice_line')
            ->id('id', IdStrategy::Max)->column('invoiceCode')->column('description'));
        $payments = new PdoRepository($pdo, EntityMapping::for(PdoShopInvoicePayment::class)->table('aspec_rel_invoice_payment')
            ->id('id', IdStrategy::Ulid)->column('invoiceCode')->column('cents')->softDelete('deleted_at'));
        $tags = PdoRepository::forEntity($pdo, PdoShopTag::class);
        $posts = PdoRepository::forEntity($pdo, PdoShopPost::class);
        $all = compact('customers', 'products', 'orders', 'items', 'invoices', 'lines', 'payments', 'tags', 'posts');
        if ($log !== null) {
            foreach ($all as $repository) {
                $repository->withQueryLogger($log);
            }
        }
        $customers->withRelated($orders);
        $orders->withRelated($customers, $items);
        $items->withRelated($orders, $products);
        $invoices->withRelated($lines, $payments);
        $posts->withRelated($tags);

        return (object) $all;
    }

    private function reset(PDO $pdo): void
    {
        foreach (['aspec_rel_post_tag', 'aspec_rel_post', 'aspec_rel_tag', 'aspec_rel_invoice_payment', 'aspec_rel_invoice_line', 'aspec_rel_invoice', 'aspec_rel_order_item', 'aspec_rel_order', 'aspec_rel_product', 'aspec_rel_customer'] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    }

    /**
     * Clientes c1 (BR), c2 (US), c3 (BR); produtos p1, p2; pedidos o1 (c1: 3×p1, 7×p2), o2 (c2: 1×p1),
     * o3 (sem cliente, sem itens), o4 (c3: 9×p2), o5 (c1: 2×p1).
     *
     * @return array<string, mixed>
     */
    private function seed(PDO $pdo): array
    {
        $this->reset($pdo);
        $shop = $this->shop($pdo);
        $c1 = new PdoShopCustomer(1, 'Ana', 'BR');
        $c2 = new PdoShopCustomer(2, 'Bob', 'US');
        $c3 = new PdoShopCustomer(3, 'Caio', 'BR');
        $shop->customers->putAll([$c1, $c2, $c3]);
        $p1 = new PdoShopProduct('p1', 'Pen');
        $p2 = new PdoShopProduct('p2', 'Paper');
        $shop->products->putAll([$p1, $p2]);
        $o1 = new PdoShopOrder('o1', $c1, [new PdoShopOrderItem(3, $p1), new PdoShopOrderItem(7, $p2)]);
        $o2 = new PdoShopOrder('o2', $c2, [new PdoShopOrderItem(1, $p1)]);
        $o3 = new PdoShopOrder('o3');
        $o4 = new PdoShopOrder('o4', $c3, [new PdoShopOrderItem(9, $p2)]);
        $o5 = new PdoShopOrder('o5', $c1, [new PdoShopOrderItem(2, $p1)]);
        $shop->orders->putAll([$o1, $o2, $o3, $o4, $o5]);

        return ['shop' => $shop, 'o1' => $o1, 'o2' => $o2, 'o3' => $o3, 'o4' => $o4, 'o5' => $o5, 'c1' => $c1, 'c2' => $c2, 'c3' => $c3];
    }

    /** @param list<PdoShopOrderItem> $items */
    private function qtys(array $items): array
    {
        return array_map(static fn(PdoShopOrderItem $i): int => $i->qty, $items);
    }

    /** @param array<PdoShopOrder> $orders */
    private function numbers(array $orders): array
    {
        return array_values(array_map(static fn(PdoShopOrder $o): string => $o->number, $orders));
    }

    /**
     * @param list<string> $statements
     * @return list<string>
     */
    private function selects(array $statements): array
    {
        return array_values(array_filter($statements, static fn(string $sql): bool => str_starts_with($sql, 'SELECT')));
    }

    ///////////////////////////////////////////////////////////////////////////
    // Carga ansiosa
    ///////////////////////////////////////////////////////////////////////////

    private function testEagerLoading(PDO $pdo, string $family): void
    {
        $data = $this->seed($pdo);
        $log = new InMemoryQueryLogger();
        $shop = $this->shop($pdo, $log);
        $all = Spec::alwaysTrue();

        // Sem with(): nada carregado, e o repositório sabe
        $plain = $shop->orders->findById($data['o1']->id);
        $this->assertTrue($plain instanceof PdoShopOrder && $plain->items === [] && $plain->customer === null, "{$family}: sem with() nada é carregado");
        $this->assertFalse($shop->orders->isLoaded($plain, 'items'), 'coleção não carregada');
        $this->assertFalse($shop->orders->isLoaded($plain, 'customer'), 'referência não carregada');
        $this->assertEquals(1, $plain->customerId, 'a chave estrangeira está lá');
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->isLoaded($plain, 'number'), 'isLoaded() só de relação');

        // with('items', 'customer'): 1 consulta de pedidos + 1 de itens + 1 de clientes
        $log->clear();
        $view = $shop->orders->with('items', 'customer');
        $this->assertTrue($view !== $shop->orders && $shop->orders->getEagerRelations() === [], 'with() é uma visão');
        $this->assertEquals(['items', 'customer'], $view->getEagerRelations());
        $orders = $view->find($all);
        $selects = $this->selects($log->statements());
        $this->assertCount(3, $log->statements(), "{$family}: 3 consultas para 5 pedidos, 5 itens, 3 clientes: " . implode(' | ', $log->statements()));
        $this->assertTrue(str_contains($selects[1], 'aspec_rel_order_item') && str_contains($selects[1], 'IN (?, ?, ?, ?, ?)') && str_contains($selects[1], 'deleted_at'), "{$family}: itens por IN, no escopo do soft delete: {$selects[1]}");
        $this->assertTrue(str_contains($selects[2], 'aspec_rel_customer') && str_contains($selects[2], 'IN (?, ?, ?)'), "{$family}: clientes distintos por IN: {$selects[2]}");
        $by = [];
        foreach ($orders as $order) {
            $by[$order->number] = $order;
        }
        $this->assertEquals([3, 7], $this->qtys($by['o1']->items), "{$family}: itens do o1, na ordem do id");
        $this->assertEquals([1], $this->qtys($by['o2']->items));
        $this->assertEquals([], $by['o3']->items, 'o3 sem itens');
        $this->assertTrue($shop->orders->isLoaded($by['o3'], 'items'), 'mas carregada (vazia de verdade)');
        $this->assertTrue($by['o1']->customer === $by['o5']->customer && $by['o1']->customer?->name === 'Ana', "{$family}: a mesma instância do cliente para os pedidos dele");
        $this->assertTrue($by['o3']->customer === null && $shop->orders->isLoaded($by['o3'], 'customer'), 'sem cliente: null carregado');
        $this->assertTrue($by['o1']->items[0]->order === $by['o1'], "{$family}: referência de volta do item ao pedido");
        $this->assertTrue($shop->items->isLoaded($by['o1']->items[0], 'order'));
        $this->assertTrue($by['o1']->items[0]->product === null && !$shop->items->isLoaded($by['o1']->items[0], 'product'), 'product não pedido');

        // Aninhado: items.product; o produto é uma instância por id
        $log->clear();
        $orders = $shop->orders->with('items.product', 'customer')->find(Spec::property('number', Spec::in('o1', 'o5')));
        $this->assertCount(4, $log->statements(), "{$family}: pedidos + itens + produtos + clientes = 4");
        $this->assertEquals(['Pen', 'Paper'], array_map(static fn(PdoShopOrderItem $i): ?string => $i->product?->title, $orders[0]->items));
        $this->assertTrue($orders[0]->items[0]->product === $orders[1]->items[0]->product, 'p1 compartilhado');

        // Três níveis a partir do cliente (OneToMany sem cascata, mappedBy escalar)
        $log->clear();
        $ana = $shop->customers->with('orders.items.product')->findById(1);
        $this->assertCount(4, $log->statements(), "{$family}: cliente + pedidos + itens + produtos");
        $this->assertEquals(['o1', 'o5'], $this->numbers($ana?->orders ?? []));
        $this->assertEquals('Paper', $ana?->orders[0]->items[1]->product?->title);

        // Item marcado não é carregado; cliente marcado deixa a referência null (a chave fica)
        $shop->items->remove($by['o1']->items[0]);
        $shop->customers->remove($data['c3']);
        $o1 = $shop->orders->with('items')->findSingle(Spec::property('number', Spec::equalTo('o1')));
        $this->assertEquals([7], $this->qtys($o1?->items ?? []), "{$family}: item marcado fica de fora");
        $o4 = $shop->orders->with('customer')->findById($data['o4']->id);
        $this->assertTrue($o4?->customer === null && $o4->customerId === 3 && $shop->orders->isLoaded($o4, 'customer'), "{$family}: cliente marcado: referência null, chave preservada");
        $page = $shop->orders->with('items')->findPage($all, 2, 0, ['number' => 'DESC']);
        $this->assertEquals(['o5', 'o4'], $this->numbers($page));
        $this->assertEquals([[2], [9]], [$this->qtys($page[0]->items), $this->qtys($page[1]->items)], 'findPage() carrega');
        $trashedView = $shop->orders->withRelated($shop->items->withTrashed())->with('items');
        $this->assertEquals([3, 7], $this->qtys($trashedView->findById($data['o1']->id)?->items ?? []), "{$family}: a visão withTrashed() do filho também vê o marcado");
        $shop->orders->withRelated($shop->items);

        // iterate(): um lote por withBatchSize()
        $log->clear();
        $batched = $shop->orders->with('items');
        $batched->withBatchSize(2);
        $count = 0;
        foreach ($batched->iterate($all) as $order) {
            $this->assertTrue($batched->isLoaded($order, 'items'));
            $count++;
        }
        $this->assertEquals(5, $count);
        // 1.14.0 (forward 032, D3): páginas por chave, cada uma lida inteira e fechada antes dos itens dela
        $this->assertCount(6, $log->statements(), "{$family}: 3 páginas de pedidos (2+2+1) + 3 lotes de itens: " . implode(' | ', $log->statements()));
        $this->assertTrue(str_contains($log->statements()[2], ':ks_after') && str_contains($log->statements()[0], 'LIMIT'), "{$family}: a página seguinte começa depois da última chave: {$log->statements()[2]}");

        // Erros claros
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->with('nope'), 'relação desconhecida');
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->with('items.nope'), 'segmento desconhecido');
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->with(), 'with() vazio');
        $bare = PdoRepository::forEntity($pdo, PdoShopOrder::class);
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $bare->with('items'), 'sem withRelated()');
        $this->assertTrue(str_contains($e->getMessage(), 'withRelated()'), $e->getMessage());
        $broken = new PdoRepository($pdo, EntityMapping::for(PdoShopInvoice::class)->table('aspec_rel_invoice')->id('code', IdStrategy::Assigned)
            ->column('customerName')->oneToMany('lines', PdoShopInvoiceLine::class, 'nothing'));
        $broken->withRelated(PdoRepository::forEntity($pdo, PdoShopOrder::class));
        $this->assertThrows(InvalidArgumentException::class, fn() => $broken->with('lines'), 'alvo não registrado');
        $broken->withRelated($shop->lines);
        $e = $this->assertThrows(EntityMappingException::class, fn() => $broken->with('lines'), 'mappedBy que não existe no filho');
        $this->assertTrue(str_contains($e->getMessage(), 'nothing'), $e->getMessage());
    }

    ///////////////////////////////////////////////////////////////////////////
    // Referência (ManyToOne)
    ///////////////////////////////////////////////////////////////////////////

    private function testReferences(PDO $pdo, string $family): void
    {
        $data = $this->seed($pdo);
        $shop = $data['shop'];
        $customerOf = fn(int $id): mixed => $this->raw($pdo, 'SELECT customer_id FROM aspec_rel_order WHERE id = ' . $id);

        $this->assertEquals(1, (int) $customerOf($data['o1']->id), "{$family}: o objeto define a chave");
        $this->assertEquals(1, $data['o1']->customerId);

        // Objeto sem identificador: recusado, nada gravado
        $item = new PdoShopOrderItem(4);
        $item->order = new PdoShopOrder('unsaved');
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $shop->items->put($item), 'referência sem id');
        $this->assertTrue(str_contains($e->getMessage(), 'put it first'), $e->getMessage());
        $this->assertEquals(5, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_order_item'));
        $this->assertEquals(5, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_order'), 'o pedido referenciado não foi gravado');

        // Não carregada: null não limpa a chave
        $plain = $shop->orders->findById($data['o1']->id);
        $plain->number = 'o1b';
        $shop->orders->put($plain);
        $this->assertEquals(1, (int) $customerOf($data['o1']->id), "{$family}: referência não carregada não apaga a chave");

        // Chave mudada direto, referência não carregada: vale a chave
        $plain->customerId = 2;
        $shop->orders->put($plain);
        $this->assertEquals(2, (int) $customerOf($data['o1']->id));

        // Carregada não nula, depois null: limpa
        $loaded = $shop->orders->with('customer')->findById($data['o1']->id);
        $this->assertEquals('Bob', $loaded?->customer?->name);
        $loaded->customer = null;
        $shop->orders->put($loaded);
        $this->assertTrue($customerOf($data['o1']->id) === null && $loaded->customerId === null, "{$family}: referência carregada e anulada limpa a chave");

        // Outro objeto: a chave segue; o referenciado nunca é gravado em cascata
        $loaded->customer = $data['c1'];
        $data['c1']->name = 'Ana alterada';
        $shop->orders->put($loaded);
        $this->assertEquals(1, (int) $customerOf($data['o1']->id));
        $this->assertEquals('Ana', $this->raw($pdo, 'SELECT name FROM aspec_rel_customer WHERE id = 1'), "{$family}: ManyToOne nunca em cascata");

        // Cliente marcado: carregada como null, mas não "presente": put não limpa
        $shop->customers->remove($data['c3']);
        $o4 = $shop->orders->with('customer')->findById($data['o4']->id);
        $this->assertTrue($o4?->customer === null);
        $shop->orders->put($o4);
        $this->assertEquals(3, (int) $customerOf($data['o4']->id), "{$family}: referência a um marcado não é anulada ao gravar");
    }

    ///////////////////////////////////////////////////////////////////////////
    // Filtro pela relação (inQuery)
    ///////////////////////////////////////////////////////////////////////////

    private function testFilterByRelation(PDO $pdo, string $family): void
    {
        $data = $this->seed($pdo);
        $shop = $data['shop'];
        $shop->customers->remove($data['c3']);
        $shop->items->remove($data['o1']->items[1]);   // o1: 7×p2 marcado

        $orders = $shop->orders->find(Spec::alwaysTrue());
        $memory = static fn(ISpecification $spec): array => array_values(array_map(
            static fn(PdoShopOrder $o): string => $o->number,
            array_filter($orders, static fn(PdoShopOrder $o): bool => $spec->isSatisfiedBy($o))
        ));
        $br = Spec::property('country', Spec::equalTo('BR'));
        $big = Spec::property('qty', Spec::greaterThan(5));
        $cases = [
            'cliente BR (c3 marcado fora)' => [$shop->orders->whereRelation('customer', $br), ['o1', 'o5']],
            'item grande vivo' => [$shop->orders->whereRelation('items', $big), ['o4']],
            'algum item' => [$shop->orders->whereRelation('items', Spec::alwaysTrue()), ['o1', 'o2', 'o4', 'o5']],
            'NOT item grande' => [Spec::not($shop->orders->whereRelation('items', $big)), ['o1', 'o2', 'o3', 'o5']],
            'combinado' => [$shop->orders->whereRelation('customer', $br)->and($shop->orders->whereRelation('items', Spec::property('productSku', Spec::equalTo('p1')))), ['o1', 'o5']],
            'inQuery explícito = whereRelation' => [Spec::inQuery('customerId', $shop->customers, $br, 'id'), ['o1', 'o5']],
        ];
        foreach ($cases as $label => [$spec, $expected]) {
            $this->assertEquals($expected, $this->numbers($shop->orders->find($spec)), "{$family}: SQL — {$label}");
            $this->assertEquals($expected, $memory($spec), "{$family}: memória — {$label}");
        }
        $sql = $shop->orders->toWhereClause($shop->orders->whereRelation('customer', $br))->toSql();
        $this->assertTrue(str_contains($sql, 'customer_id') && str_contains($sql, 'IN (SELECT') && str_contains($sql, 'aspec_rel_customer'), "{$family}: um comando: {$sql}");
        $this->assertEquals(['Ana'], array_map(
            static fn(PdoShopCustomer $c): string => $c->name,
            $shop->customers->find($shop->customers->whereRelation('orders', Spec::property('number', Spec::equalTo('o5'))))
        ), "{$family}: pelo lado do cliente");
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->whereRelation('number', $br), 'só relação');
    }

    ///////////////////////////////////////////////////////////////////////////
    // Composição: gravação e órfãos
    ///////////////////////////////////////////////////////////////////////////

    private function testComposition(PDO $pdo, string $family): void
    {
        $this->reset($pdo);
        $shop = $this->shop($pdo);
        $shop->products->putAll([new PdoShopProduct('p1', 'Pen')]);
        $live = fn(int $orderId): array => array_map('intval', array_column(
            $pdo->query('SELECT qty FROM aspec_rel_order_item WHERE deleted_at IS NULL AND order_id = ' . $orderId . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'qty'
        ));
        $raw = fn(int $orderId): int => (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_order_item WHERE order_id = ' . $orderId);

        // afterPut do pai roda depois dos filhos gravados
        $seen = [];
        $shop->orders->afterPut(function (PdoShopOrder $order) use (&$seen, $pdo): void {
            $seen[] = $order->id === null ? -1 : (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_order_item WHERE order_id = ' . $order->id);
        });
        $order = new PdoShopOrder('a', null, [new PdoShopOrderItem(1), new PdoShopOrderItem(2), new PdoShopOrderItem(3)]);
        $shop->orders->put($order);
        $id = (int) $order->id;
        $this->assertEquals([1, 2, 3], $live($id), "{$family}: novo pedido grava os itens");
        $this->assertEquals([3], $seen, "{$family}: afterPut vê os filhos já gravados");
        $this->assertTrue($order->items[0]->id !== null && $order->items[0]->orderId === $id && $order->items[0]->order === $order, 'id, chave e referência de volta');
        $this->assertTrue($shop->orders->isLoaded($order, 'items'), 'pedido inserido: a coleção é a verdade');

        // Coleção NÃO carregada nunca é vazia
        $plain = $shop->orders->findById($id);
        $this->assertTrue($plain?->items === [] && !$shop->orders->isLoaded($plain, 'items'));
        $plain->number = 'a2';
        $shop->orders->put($plain);
        $this->assertEquals([1, 2, 3], $live($id), "{$family}: gravar com a coleção não carregada não apaga filhos");
        $plain->items = [new PdoShopOrderItem(4)];
        $shop->orders->put($plain);
        $this->assertEquals([1, 2, 3, 4], $live($id), "{$family}: coleção não carregada com um filho: só o upsert");
        $this->assertFalse($shop->orders->isLoaded($plain, 'items'), 'continua não carregada (o banco tem mais)');

        // Carregada: órfão removido (marcado: o item tem soft delete)
        $loaded = $shop->orders->with('items')->findById($id);
        $this->assertEquals([1, 2, 3, 4], $this->qtys($loaded?->items ?? []));
        $loaded->items = array_values(array_filter($loaded->items, static fn(PdoShopOrderItem $i): bool => $i->qty !== 2));
        $loaded->items[0]->qty = 10;
        $shop->orders->put($loaded);
        $this->assertEquals([10, 3, 4], $live($id), "{$family}: órfão removido, alterado gravado");
        $this->assertEquals(4, $raw($id), 'marcado, não apagado (soft delete do filho)');

        // Filho adicionado por outro depois da carga: não é órfão desta coleção
        $other = new PdoShopOrderItem(5);
        $other->order = $loaded;
        $shop->items->put($other);
        $this->assertEquals($id, $other->orderId, 'chave pela referência');
        array_pop($loaded->items);   // tira o 4
        $shop->orders->put($loaded);
        $this->assertEquals([10, 3, 5], $live($id), "{$family}: só o 4 sai; o 5, adicionado por outro, fica");

        // Coleção carregada esvaziada
        $again = $shop->orders->with('items')->findById($id);
        $again->items = [];
        $shop->orders->put($again);
        $this->assertEquals([], $live($id), "{$family}: coleção carregada vazia remove todos");

        // Mover um filho entre dois pais na MESMA chamada
        $x = new PdoShopOrder('x', null, [new PdoShopOrderItem(7), new PdoShopOrderItem(8)]);
        $y = new PdoShopOrder('y');
        $shop->orders->putAll([$x, $y]);
        [$x, $y] = [$shop->orders->with('items')->findById($x->id), $shop->orders->with('items')->findById($y->id)];
        $moved = array_shift($x->items);
        $y->items[] = $moved;
        $shop->orders->putAll([$x, $y]);
        $this->assertEquals([8], $live((int) $x->id));
        $this->assertEquals([7], $live((int) $y->id), "{$family}: filho movido na mesma chamada não é órfão");
        $this->assertTrue($moved->order === $y && $moved->orderId === $y->id);

        // insertAll() em cascata
        $batch = [new PdoShopOrder('i1', null, [new PdoShopOrderItem(1), new PdoShopOrderItem(1)]), new PdoShopOrder('i2', null, [new PdoShopOrderItem(2)])];
        $this->assertEquals(2, $shop->orders->insertAll($batch));
        $this->assertEquals([1, 1], $live((int) $batch[0]->id));
        $this->assertEquals([2], $live((int) $batch[1]->id), "{$family}: insertAll() insere os filhos");

        // Coleção com objeto de outra classe: recusada antes de gravar filho
        $wrong = $shop->orders->findById($id);
        $wrong->items = [new PdoShopProduct('z', 'z')];
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->put($wrong), 'filho de outra classe');
    }

    ///////////////////////////////////////////////////////////////////////////
    // Remoção em cascata
    ///////////////////////////////////////////////////////////////////////////

    private function testCascadeRemoval(PDO $pdo, string $family): void
    {
        $data = $this->seed($pdo);
        $shop = $data['shop'];
        $count = fn(string $sql): int => (int) $this->raw($pdo, $sql);

        // Pedido (sem soft delete): os itens são APAGADOS, inclusive os já marcados
        $o1 = $data['o1'];
        $shop->items->remove($o1->items[0]);
        $this->assertTrue($shop->orders->remove($o1), "{$family}: remove() do pai");
        $this->assertEquals(0, $count('SELECT COUNT(*) FROM aspec_rel_order_item WHERE order_id = ' . $o1->id), "{$family}: todas as linhas dos itens apagadas (a FK exige)");
        $this->assertEquals(0, $count('SELECT COUNT(*) FROM aspec_rel_order WHERE id = ' . $o1->id));
        $this->assertFalse($shop->orders->remove($o1), 'de novo: false, nada em cascata');

        // removeBy() do pai: carrega os pais e remove os filhos de todos
        $this->assertEquals(2, $shop->orders->removeBy(Spec::property('number', Spec::in('o2', 'o4'))));
        $this->assertEquals(1, $count('SELECT COUNT(*) FROM aspec_rel_order_item'), "{$family}: sobra o item do o5");

        // Nota fiscal (soft delete): pagamentos (soft) marcados, linhas (sem soft) mantidas
        $invoice = new PdoShopInvoice('nf1', 'Ana', [new PdoShopInvoiceLine('a'), new PdoShopInvoiceLine('b')], [new PdoShopInvoicePayment(100), new PdoShopInvoicePayment(200)]);
        $shop->invoices->put($invoice);
        $this->assertEquals([1, 2], [$invoice->lines[0]->id, $invoice->lines[1]->id], "{$family}: ids Max em lote");
        $this->assertTrue($shop->invoices->remove($invoice), "{$family}: remoção lógica");
        $this->assertEquals(1, $count("SELECT COUNT(*) FROM aspec_rel_invoice WHERE code = 'nf1' AND deleted_at IS NOT NULL"));
        $this->assertEquals(2, $count("SELECT COUNT(*) FROM aspec_rel_invoice_payment WHERE invoice_code = 'nf1' AND deleted_at IS NOT NULL"), "{$family}: pagamentos marcados com a nota");
        $this->assertEquals(2, $count("SELECT COUNT(*) FROM aspec_rel_invoice_line WHERE invoice_code = 'nf1'"), "{$family}: linhas sem soft delete ficam com a linha da nota");
        $this->assertFalse($shop->invoices->remove($invoice), 'de novo: false');

        // Reviver pelo put() do objeto que ainda tem as coleções: revive os pagamentos que ele traz
        $shop->invoices->put($invoice);
        $reloaded = $shop->invoices->with('lines', 'payments')->findById('nf1');
        $this->assertEquals([2, 2], [count($reloaded?->lines ?? []), count($reloaded?->payments ?? [])], "{$family}: nota e pagamentos de volta");

        // removeBy() lógico
        $this->assertEquals(1, $shop->invoices->removeBy(Spec::property('code', Spec::equalTo('nf1'))));
        $this->assertEquals(0, $shop->payments->count(Spec::alwaysTrue()), "{$family}: removeBy() marca os pagamentos");
        $this->assertEquals(2, $shop->lines->count(Spec::alwaysTrue()));

        // forceRemove(): apaga tudo, marcados inclusive
        $this->assertTrue($shop->invoices->forceRemove($invoice), "{$family}: forceRemove() de nota marcada");
        foreach (['aspec_rel_invoice', 'aspec_rel_invoice_line', 'aspec_rel_invoice_payment'] as $table) {
            $this->assertEquals(0, $count('SELECT COUNT(*) FROM ' . $table), "{$family}: {$table} vazia");
        }
        $this->assertFalse($shop->invoices->forceRemove($invoice), 'de novo: false');

        // Pai sem filhos carregados: a cascata vai pelo banco, não pela coleção do objeto
        $shop->invoices->put(new PdoShopInvoice('nf2', 'Bob', [new PdoShopInvoiceLine('x')]));
        $bare = $shop->invoices->findById('nf2');
        $this->assertTrue($bare?->lines === []);
        $this->assertTrue($shop->invoices->forceRemove($bare));
        $this->assertEquals(0, $count('SELECT COUNT(*) FROM aspec_rel_invoice_line'), "{$family}: forceRemove() com a coleção não carregada apaga os filhos do banco");
    }

    ///////////////////////////////////////////////////////////////////////////
    // Transação
    ///////////////////////////////////////////////////////////////////////////

    private function testRollback(PDO $pdo, string $family): void
    {
        $this->reset($pdo);
        $shop = $this->shop($pdo);
        $count = fn(string $table): int => (int) $this->raw($pdo, 'SELECT COUNT(*) FROM ' . $table);

        $bad = new PdoShopOrder('bad', null, [new PdoShopOrderItem(1), new PdoShopOrderItem(0)]);
        $this->assertThrows(PdoRepositoryException::class, fn() => $shop->orders->put($bad), "{$family}: filho viola o CHECK");
        $this->assertEquals(0, $count('aspec_rel_order'), "{$family}: nem o pai ficou");
        $this->assertEquals(0, $count('aspec_rel_order_item'));

        $good = new PdoShopOrder('good', null, [new PdoShopOrderItem(1), new PdoShopOrderItem(2)]);
        $shop->orders->put($good);
        $loaded = $shop->orders->with('items')->findById($good->id);
        array_shift($loaded->items);            // órfão
        $loaded->items[0]->qty = 20;            // alterado
        $loaded->items[] = new PdoShopOrderItem(0);   // inválido
        $loaded->number = 'changed';
        $this->assertThrows(PdoRepositoryException::class, fn() => $shop->orders->put($loaded), "{$family}: falha no meio da cascata");
        $this->assertEquals('good', $this->raw($pdo, 'SELECT number FROM aspec_rel_order WHERE id = ' . $good->id), "{$family}: pai intacto");
        $this->assertEquals(2, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_order_item WHERE deleted_at IS NULL'), "{$family}: o órfão não foi removido");
        $this->assertEquals(2, (int) $this->raw($pdo, 'SELECT qty FROM aspec_rel_order_item ORDER BY id DESC'), "{$family}: a alteração do filho foi desfeita");

        // Versão velha do pai: nada dos filhos
        $first = $shop->orders->with('items')->findById($good->id);
        $second = $shop->orders->with('items')->findById($good->id);
        $first->number = 'first';
        $shop->orders->put($first);
        $second->items = [];
        $this->assertThrows(OptimisticLockException::class, fn() => $shop->orders->put($second), "{$family}: cópia velha do pai");
        $this->assertEquals(2, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_order_item WHERE deleted_at IS NULL'), "{$family}: e nenhum filho removido");

        // Nota com pagamento inválido: nada
        $invoice = new PdoShopInvoice('nf9', 'Eva', [new PdoShopInvoiceLine('l')], [new PdoShopInvoicePayment(0)]);
        $this->assertThrows(PdoRepositoryException::class, fn() => $shop->invoices->put($invoice));
        $this->assertEquals(0, $count('aspec_rel_invoice') + $count('aspec_rel_invoice_line') + $count('aspec_rel_invoice_payment'), "{$family}: nota, linhas e pagamentos desfeitos");

        // Remoção em cascata desfeita quando a remoção do pai falha (versão)
        $stale = $shop->orders->findById($good->id);
        $fresh = $shop->orders->findById($good->id);
        $fresh->number = 'fresh';
        $shop->orders->put($fresh);
        $this->assertThrows(OptimisticLockException::class, fn() => $shop->orders->remove($stale), "{$family}: remover cópia velha");
        $this->assertEquals(2, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_order_item'), "{$family}: os itens apagados em cascata voltaram");
    }

    ///////////////////////////////////////////////////////////////////////////
    // Eventos de domínio
    ///////////////////////////////////////////////////////////////////////////

    private function testDomainEvents(PDO $pdo, string $family): void
    {
        // Filho DECORADO: a cascata passa pelo decorador
        $this->reset($pdo);
        $shop = $this->shop($pdo);
        $publisher = new InMemoryDomainEventPublisher();
        $triggers = new TriggerSet(new SpecificationTrigger('item.big', Spec::property('qty', Spec::greaterThan(5)), onEnter: 'item.big.entered', onExit: 'item.big.left'));
        $decorated = new TriggeringRepository($shop->items, $triggers, $publisher, $shop->items->getTransactionManager());
        $shop->orders->withRelated($decorated);

        $order = new PdoShopOrder('e', null, [new PdoShopOrderItem(9), new PdoShopOrderItem(1)]);
        $shop->orders->put($order);
        $this->assertEquals(['item.big.entered'], $publisher->publishedNames(), "{$family}: filho decorado: entrada publicada na cascata");
        $publisher->drain();

        $loaded = $shop->orders->with('items')->findById($order->id);
        $loaded->items = [$loaded->items[1]];   // o grande vira órfão
        $shop->orders->put($loaded);
        $this->assertEquals(['item.big.left'], $publisher->publishedNames(), "{$family}: órfão removido pelo decorador: saída");
        $publisher->drain();

        $loaded->items[] = new PdoShopOrderItem(8);
        $shop->orders->put($loaded);
        $this->assertEquals(['item.big.entered'], $publisher->publishedNames());
        $publisher->drain();

        $this->assertTrue($shop->orders->remove($loaded));
        $this->assertEquals(['item.big.left'], $publisher->publishedNames(), "{$family}: remoção do pai: saída do filho vivo, uma vez");
        $this->assertEquals(0, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_order_item'), "{$family}: e as linhas apagadas (pai sem soft delete)");
        $publisher->drain();

        // Pai decorado também: as duas camadas publicam
        $orderEvents = new InMemoryDomainEventPublisher();
        $orders = new TriggeringRepository($shop->orders, new TriggerSet(new SpecificationTrigger('order.any', Spec::property('number', Spec::isNotNull()), onEnter: 'order.created', onExit: 'order.gone')), $orderEvents, $shop->orders->getTransactionManager());
        $p = new PdoShopOrder('p', null, [new PdoShopOrderItem(6)]);
        $orders->put($p);
        $this->assertEquals(['order.created'], $orderEvents->publishedNames());
        $this->assertEquals(['item.big.entered'], $publisher->publishedNames(), "{$family}: pai decorado + filho decorado");
        $orders->removeBy(Spec::property('number', Spec::equalTo('p')));
        $this->assertEquals(['order.created', 'order.gone'], $orderEvents->publishedNames());
        $this->assertEquals(['item.big.entered', 'item.big.left'], $publisher->publishedNames(), "{$family}: removeBy() pelo decorador do pai: saída do filho");

        // Filho SEM decorador: a cascata não publica; o varredor percebe a saída
        $this->reset($pdo);
        $shop = $this->shop($pdo);
        $silent = new InMemoryDomainEventPublisher();
        $now = new DateTimeImmutable('2026-10-11 12:00:00Z');
        $timed = new TriggerSet(SpecificationTrigger::timed('item.positive', static fn(DateTimeImmutable $at): ISpecification => Spec::property('qty', Spec::greaterThan(0)), onEnter: 'item.positive.entered', onExit: 'item.positive.left'));
        $states = new InMemoryTriggerStateStore();
        $sweeper = new TriggerSweeper($shop->items, PdoShopOrderItem::class, $timed, $states, $silent, $shop->items->getTransactionManager());
        $order = new PdoShopOrder('s', null, [new PdoShopOrderItem(3), new PdoShopOrderItem(4)]);
        $shop->orders->put($order);
        $this->assertEquals(2, $sweeper->baseline($now)->recordedCount());
        $loaded = $shop->orders->with('items')->findById($order->id);
        array_shift($loaded->items);
        $shop->orders->put($loaded);
        $this->assertEquals([], $silent->publishedNames(), "{$family}: filho sem decorador: nada publicado na cascata");
        $this->assertEquals(1, $sweeper->sweep($now)->exitedCount(), "{$family}: o varredor vê o órfão marcado como saída");
        $shop->orders->remove($loaded);
        $this->assertEquals(1, $sweeper->sweep($now)->exitedCount(), "{$family}: e o filho apagado com o pai");
        $this->assertEquals(['item.positive.left', 'item.positive.left'], $silent->publishedNames());
    }

    ///////////////////////////////////////////////////////////////////////////
    // 1.14.0 (forward 032, D1): ManyToMany — associação pela tabela de junção
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Os vínculos gravados, como "post:tag" em ordem.
     *
     * @return list<string>
     */
    private function links(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT post_id, tag_id FROM aspec_rel_post_tag ORDER BY post_id, tag_id')->fetchAll(PDO::FETCH_NUM);

        return array_map(static fn(array $r): string => (int) $r[0] . ':' . (int) $r[1], $rows);
    }

    /** As linhas da tabela de etiquetas, cruas (prova de que nenhum alvo foi escrito). */
    private function tagRows(PDO $pdo): array
    {
        return array_map(
            static fn(array $r): string => (int) $r[0] . '=' . $r[1] . ($r[2] === null ? '' : '†'),
            $pdo->query('SELECT id, name, deleted_at FROM aspec_rel_tag ORDER BY id')->fetchAll(PDO::FETCH_NUM)
        );
    }

    /** @param list<PdoShopTag> $tags */
    private function tagNames(array $tags): array
    {
        return array_map(static fn(PdoShopTag $t): string => $t->name, $tags);
    }

    private function testManyToMany(PDO $pdo, string $family): void
    {
        $this->reset($pdo);
        $log = new InMemoryQueryLogger();
        $shop = $this->shop($pdo, $log);
        [$php, $sql, $go, $rust] = [new PdoShopTag(1, 'php'), new PdoShopTag(2, 'sql'), new PdoShopTag(3, 'go'), new PdoShopTag(4, 'rust')];
        $shop->tags->putAll([$php, $sql, $go, $rust]);
        $tagsBefore = $this->tagRows($pdo);

        // Dono novo com coleção: os vínculos entram; os alvos não são escritos
        $log->clear();
        $p1 = new PdoShopPost('p1', [$php, $sql]);
        $p2 = new PdoShopPost('p2', [$sql, $sql]);
        $p3 = new PdoShopPost('p3');
        $shop->posts->putAll([$p1, $p2, $p3]);
        $this->assertEquals(["{$p1->id}:1", "{$p1->id}:2", "{$p2->id}:2"], $this->links($pdo), "{$family}: vínculos dos donos novos (alvo repetido vira um vínculo)");
        $this->assertEquals($tagsBefore, $this->tagRows($pdo), "{$family}: nenhuma etiqueta escrita");
        $touchedTags = array_filter($log->statements(), static fn(string $q): bool => str_contains($q, 'aspec_rel_tag"') && !str_starts_with($q, 'SELECT'));
        $this->assertEquals([], array_values($touchedTags), "{$family}: nenhum comando de escrita na tabela dos alvos");
        $this->assertTrue($shop->posts->isLoaded($p1, 'tags') && $shop->posts->isLoaded($p3, 'tags'), 'dono inserido: coleção conhecida');

        // with('tags'): consulta de donos + junção + alvos (número fixo)
        $log->clear();
        $loaded = $shop->posts->with('tags')->find(Spec::alwaysTrue());
        $this->assertCount(3, $log->statements(), "{$family}: 3 consultas: " . implode(' | ', $log->statements()));
        [$q0, $q1, $q2] = $log->statements();
        $this->assertTrue(str_contains($q1, 'aspec_rel_post_tag') && str_contains($q1, 'IN (?, ?, ?)') && str_contains($q1, 'ORDER BY'), "{$family}: junção por IN: {$q1}");
        $this->assertTrue(str_contains($q2, 'aspec_rel_tag') && str_contains($q2, 'IN (?, ?)') && str_contains($q2, 'deleted_at'), "{$family}: alvos por IN, no escopo do soft delete: {$q2}");
        $by = [];
        foreach ($loaded as $post) {
            $by[$post->title] = $post;
        }
        $this->assertEquals(['php', 'sql'], $this->tagNames($by['p1']->tags));
        $this->assertEquals(['sql'], $this->tagNames($by['p2']->tags));
        $this->assertTrue($by['p3']->tags === [] && $shop->posts->isLoaded($by['p3'], 'tags'), 'p3: carregada e vazia de verdade');
        $this->assertTrue($by['p1']->tags[1] === $by['p2']->tags[0], "{$family}: uma instância por alvo no lote");

        // Sincronização de coleção carregada: entra go, sai php; alvo alterado não é gravado
        $post = $by['p1'];
        $post->tags = [$post->tags[1], $go];
        $go->name = 'golang';
        $log->clear();
        $shop->posts->put($post);
        $go->name = 'go';
        $this->assertEquals(["{$p1->id}:2", "{$p1->id}:3", "{$p2->id}:2"], $this->links($pdo), "{$family}: vínculo novo inserido, o que saiu apagado");
        $this->assertEquals($tagsBefore, $this->tagRows($pdo), "{$family}: alvos nunca escritos nem apagados (nem o nome alterado)");
        $writes = array_values(array_filter($log->statements(), static fn(string $q): bool => !str_starts_with($q, 'SELECT')));
        $this->assertEquals(3, count($writes), "{$family}: UPDATE do dono + DELETE + INSERT na junção: " . implode(' | ', $writes));
        $this->assertTrue(str_starts_with($writes[1], 'DELETE FROM') && str_contains($writes[1], 'aspec_rel_post_tag') && str_starts_with($writes[2], 'INSERT INTO') && str_contains($writes[2], 'aspec_rel_post_tag'));

        // Coleção NÃO carregada: nunca toca a junção (nem vazia, nem com itens)
        $bare = $shop->posts->findById($p1->id);
        $this->assertTrue($bare->tags === [] && !$shop->posts->isLoaded($bare, 'tags'), 'sem with(): não carregada');
        $log->clear();
        $bare->title = 'p1b';
        $shop->posts->put($bare);
        $bare->tags = [$rust];
        $shop->posts->put($bare);
        $this->assertEquals(["{$p1->id}:2", "{$p1->id}:3", "{$p2->id}:2"], $this->links($pdo), "{$family}: coleção não carregada não remove nem acrescenta vínculo");
        $joinStatements = array_filter($log->statements(), static fn(string $q): bool => str_contains($q, 'aspec_rel_post_tag'));
        $this->assertEquals([], array_values($joinStatements), "{$family}: nenhum comando na tabela de junção");

        // Alvo sem id ou de outra classe: recusado, nada gravado
        $loaded = $shop->posts->with('tags')->findById($p1->id);
        $loaded->tags[] = new PdoShopTag(null, 'new');
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $shop->posts->put($loaded), 'alvo sem id');
        $this->assertTrue(str_contains($e->getMessage(), 'no identifier'), $e->getMessage());
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->posts->put(new PdoShopPost('n', [new PdoShopTag(null, 'x')])), 'dono novo com alvo sem id');
        $this->assertEquals(0, (int) $this->raw($pdo, "SELECT COUNT(*) FROM aspec_rel_post WHERE title = 'n'"), "{$family}: o dono não ficou gravado");
        $loaded->tags = [new PdoShopProduct('z', 'z')];
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->posts->put($loaded), 'alvo de outra classe');

        // Alvo com soft delete: fora da coleção carregada, o vínculo fica (e não é apagado ao regravar)
        $shop->tags->remove($sql);
        $p2loaded = $shop->posts->with('tags')->findById($p2->id);
        $this->assertEquals([], $p2loaded->tags, "{$family}: alvo marcado fora da coleção");
        $shop->posts->put($p2loaded);
        $this->assertTrue(in_array("{$p2->id}:2", $this->links($pdo), true), "{$family}: o vínculo ao alvo marcado permanece");
        $this->assertEquals(['go'], $this->tagNames($shop->posts->with('tags')->findById($p1->id)->tags));
        $this->assertEquals(['sql', 'go'], $this->tagNames($shop->posts->withRelated($shop->tags->withTrashed())->with('tags')->findById($p1->id)->tags), "{$family}: a visão withTrashed() do alvo vê o marcado");
        $shop->posts->withRelated($shop->tags);

        // whereRelation(): subconsulta pela junção; memória = SQL; alvo marcado não casa
        $byTag = fn(string $name) => $shop->posts->whereRelation('tags', Spec::property('name', Spec::equalTo($name)));
        $where = $shop->posts->toWhereClause($byTag('go'))->toSql();
        $this->assertTrue(str_contains($where, 'IN (SELECT "post_id" FROM "aspec_rel_post_tag" WHERE "tag_id" IN (SELECT') || str_contains($where, 'IN (SELECT `post_id` FROM `aspec_rel_post_tag` WHERE `tag_id` IN (SELECT'), "{$family}: {$where}");
        $this->assertEquals(['p1b'], array_map(static fn(PdoShopPost $p): string => $p->title, $shop->posts->find($byTag('go'))));
        $this->assertEquals(0, $shop->posts->count($byTag('sql')), "{$family}: alvo marcado não casa");
        $all = $shop->posts->find(Spec::alwaysTrue());
        foreach ([$byTag('go'), Spec::not($byTag('go')), $byTag('php')->or($byTag('go')), Spec::not($byTag('sql'))] as $spec) {
            $memory = array_values(array_map(static fn(PdoShopPost $p): int => (int) $p->id, array_filter($all, static fn(PdoShopPost $p): bool => $spec->isSatisfiedBy($p))));
            $found = array_map(static fn(PdoShopPost $p): int => (int) $p->id, $shop->posts->find($spec));
            $this->assertEquals($memory, $found, "{$family}: whereRelation() memória = SQL: {$spec}");
        }
        $this->assertTrue($byTag('go')->equals($byTag('go')) && !$byTag('go')->equals($byTag('php')), 'equals() da folha pela junção');

        // insertAll(): vínculos dos novos donos
        $batch = [new PdoShopPost('i1', [$php]), new PdoShopPost('i2', [$php, $rust])];
        $shop->posts->insertAll($batch);
        $this->assertEquals([1, 4], array_map(static fn(PdoShopTag $t): int => (int) $t->id, $shop->posts->with('tags')->findById($batch[1]->id)->tags), "{$family}: insertAll() grava os vínculos");

        // Remoção do dono: soft delete mantém os vínculos; remoção física os apaga; alvos intactos
        $before = count($this->links($pdo));
        $this->assertTrue($shop->posts->remove($batch[1]));
        $this->assertEquals($before, count($this->links($pdo)), "{$family}: soft delete do dono mantém os vínculos");
        $this->assertTrue($shop->posts->forceRemove($batch[1]));
        $this->assertEquals($before - 2, count($this->links($pdo)), "{$family}: remoção física apaga os vínculos dele");
        $this->assertEquals(4, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_tag'), "{$family}: nenhuma etiqueta apagada");

        // Dono SEM soft delete: remove() e removeBy() (um comando e com gancho) apagam os vínculos antes
        $hard = new PdoRepository($pdo, EntityMapping::for(PdoShopPost::class)->table('aspec_rel_post')->id('id', IdStrategy::Auto)->column('title')
            ->manyToMany('tags', PdoShopTag::class, 'aspec_rel_post_tag', 'post_id', 'tag_id'));
        $hard->withRelated($shop->tags);
        $h = [new PdoShopPost('h1', [$php, $go]), new PdoShopPost('h2', [$go]), new PdoShopPost('h3', [$rust])];
        $hard->putAll($h);
        $this->assertTrue($hard->remove($h[0]));
        $this->assertEquals(0, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_post_tag WHERE post_id = ' . $h[0]->id), "{$family}: remove() sem soft delete apaga os vínculos");
        $log->clear();
        $hard->withQueryLogger($log);
        $this->assertEquals(1, $hard->removeAllEntitiesSpecifiedBy(Spec::property('title', Spec::equalTo('h2'))));
        $this->assertEquals(0, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_post_tag WHERE post_id = ' . $h[1]->id), "{$family}: removeBy() em um comando por tabela");
        $this->assertTrue(str_contains($log->statements()[0], 'DELETE FROM') && str_contains($log->statements()[0], 'IN (SELECT'), "{$family}: vínculos pelo mesmo filtro: {$log->statements()[0]}");
        $hard->beforeRemove(static function (): void {
        });
        $this->assertEquals(1, $hard->removeAllEntitiesSpecifiedBy(Spec::property('title', Spec::equalTo('h3'))));
        $this->assertEquals(0, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_post_tag WHERE post_id = ' . $h[2]->id), "{$family}: removeBy() com gancho (por entidade) também");

        // A transação desfaz os vínculos com o dono
        $versioned = $shop->posts->with('tags')->findById($p1->id);
        $versioned->tags = [$php, $go, $rust];
        $shop->posts->afterPut(static function (): void {
            throw new \RuntimeException('afterPut recusa');
        });
        $linksBefore = $this->links($pdo);
        $this->assertThrows(\RuntimeException::class, fn() => $shop->posts->put($versioned), 'gancho depois dos vínculos');
        $this->assertEquals($linksBefore, $this->links($pdo), "{$family}: rollback desfaz os vínculos");
    }

    ///////////////////////////////////////////////////////////////////////////
    // 1.14.0 (forward 032, D2): markLoaded() / markUnloaded()
    ///////////////////////////////////////////////////////////////////////////

    private function testMarkLoaded(PDO $pdo, string $family): void
    {
        $data = $this->seed($pdo);
        $shop = $data['shop'];
        $o1 = $shop->orders->with('items')->findById($data['o1']->id);
        [$i3, $i7] = $o1->items;
        $live = fn(int $orderId): array => array_map('intval', array_column(
            $pdo->query('SELECT qty FROM aspec_rel_order_item WHERE deleted_at IS NULL AND order_id = ' . $orderId . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'qty'
        ));

        // Agregado montado à mão SEM markLoaded: nenhum órfão (coleção não carregada)
        $hand = $shop->orders->findById($o1->id);
        $hand->items = [$this->itemCopy($i3)];
        $shop->orders->put($hand);
        $this->assertEquals([3, 7], $live((int) $o1->id), "{$family}: sem markLoaded() nada é removido");

        // markLoaded() com a coleção completa; o que sair dela depois é órfão (relativo a ela)
        $hand = $shop->orders->findById($o1->id);
        $hand->items = [$this->itemCopy($i3), $this->itemCopy($i7)];
        $shop->orders->markLoaded($hand, 'items');
        $this->assertTrue($shop->orders->isLoaded($hand, 'items'));
        array_pop($hand->items);
        $shop->orders->put($hand);
        $this->assertEquals([3], $live((int) $o1->id), "{$family}: o filho que saiu da coleção declarada é órfão");

        // O PERIGO documentado: coleção incompleta declarada apaga o que falta
        $o5 = $shop->orders->with('items')->findById($data['o5']->id);
        $extra = new PdoShopOrderItem(4);
        $extra->order = $o5;
        $shop->items->put($extra);   // agora o5 tem os itens 2 e 4
        $partial = $shop->orders->findById($o5->id);
        $partial->items = [$this->itemCopy($o5->items[0])];   // só o 2: o 4 não está aqui
        $shop->orders->markLoaded($partial, 'items');
        $shop->orders->put($partial);
        $this->assertEquals([2], $live((int) $o5->id), "{$family}: PERIGO — a coleção declarada incompleta removeu o 4");

        // Depois do put() a coleção volta a ser "carregada" comum: o que outro acrescentar fica
        $late = new PdoShopOrderItem(9);
        $late->orderId = $o5->id;
        $shop->items->put($late);
        $shop->orders->put($partial);
        $this->assertEquals([2, 9], $live((int) $o5->id), "{$family}: depois da gravação, só o que foi carregado com ela pode sair");

        // markUnloaded(): a coleção volta a não dizer nada
        $o4 = $shop->orders->with('items')->findById($data['o4']->id);
        $shop->orders->markUnloaded($o4, 'items');
        $this->assertFalse($shop->orders->isLoaded($o4, 'items'));
        $o4->items = [];
        $shop->orders->put($o4);
        $this->assertEquals([9], $live((int) $o4->id), "{$family}: markUnloaded(): coleção vazia não remove");

        // ManyToMany: declarada inteira (o vínculo ao alvo marcado fica); markUnloaded não toca a junção
        $php = new PdoShopTag(1, 'php');
        $sql = new PdoShopTag(2, 'sql');
        $go = new PdoShopTag(3, 'go');
        $shop->tags->putAll([$php, $sql, $go]);
        $post = new PdoShopPost('m', [$php, $sql, $go]);
        $shop->posts->put($post);
        $shop->tags->remove($go);   // marcada: o vínculo fica
        $pdo->exec('INSERT INTO aspec_rel_tag (id, name) VALUES (4, \'rust\')');
        $pdo->exec('INSERT INTO aspec_rel_post_tag (post_id, tag_id) VALUES (' . $post->id . ', 4)');   // acrescentado por outro
        $handPost = $shop->posts->findById($post->id);
        $handPost->tags = [new PdoShopTag(1, 'php')];
        $shop->posts->markLoaded($handPost, 'tags');
        $shop->posts->put($handPost);
        $this->assertEquals(["{$post->id}:1", "{$post->id}:3"], $this->links($pdo), "{$family}: declarada inteira: sai o sql e o rust (visíveis), fica o go (marcado)");
        $this->assertEquals(4, (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_tag'), 'nenhum alvo apagado');
        $loaded = $shop->posts->with('tags')->findById($post->id);
        $shop->posts->markUnloaded($loaded, 'tags');
        $loaded->tags = [];
        $shop->posts->put($loaded);
        $this->assertEquals(["{$post->id}:1", "{$post->id}:3"], $this->links($pdo), "{$family}: markUnloaded(): a junção não é tocada");

        // Erros claros
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->markLoaded($o1, 'customer'), 'referência não é coleção');
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->markLoaded($o1, 'nope'), 'relação desconhecida');
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->markLoaded($o1), 'sem relação');
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->markLoaded($handPost, 'items'), 'entidade de outra classe');
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->markUnloaded($o1, 'customer'), 'markUnloaded só de coleção');
        $wrong = $shop->orders->findById($o1->id);
        $wrong->items = [new PdoShopProduct('z', 'z')];
        $this->assertThrows(InvalidArgumentException::class, fn() => $shop->orders->markLoaded($wrong, 'items'), 'coleção com outra classe');
        $this->assertThrows(InvalidArgumentException::class, fn() => PdoRepository::forEntity($pdo, PdoShopOrder::class)->markLoaded($o1, 'items'), 'sem withRelated()');
    }

    /** Uma cópia "montada à mão" de um item gravado (mesmo id, mesma versão de soft delete). */
    private function itemCopy(PdoShopOrderItem $item): PdoShopOrderItem
    {
        $copy = new PdoShopOrderItem($item->qty);
        $copy->id = $item->id;
        $copy->productSku = $item->productSku;

        return $copy;
    }

    ///////////////////////////////////////////////////////////////////////////
    // 1.14.0 (forward 032, D3): iterate() com relações, paginado por chave
    ///////////////////////////////////////////////////////////////////////////

    /**
     * A conexão rastreada (SQLite) prova a propriedade que o MySQL sem buffer exige: nenhum comando
     * executa enquanto outro tem um cursor aberto. No MySQL, a mesma bateria roda numa conexão SEM
     * buffer (testIterateUnbufferedMySql()).
     */
    private function testIterateWithRelations(PDO $pdo, string $family): void
    {
        $data = $this->seed($pdo);
        $shop = $data['shop'];
        $tags = [new PdoShopTag(1, 'a'), new PdoShopTag(2, 'b')];
        $shop->tags->putAll($tags);
        $shop->posts->putAll([new PdoShopPost('x', $tags), new PdoShopPost('y', [$tags[1]]), new PdoShopPost('z')]);
        $all = Spec::alwaysTrue();

        $expected = [];
        foreach ($shop->orders->with('items.product', 'customer')->find($all) as $order) {
            $expected[] = $order->number . ':' . implode(',', $this->qtys($order->items)) . ':' . ($order->customer?->name ?? '-');
        }
        foreach ([1, 2, 3, 100] as $size) {
            $view = $shop->orders->with('items.product', 'customer');
            $view->withBatchSize($size);
            $got = [];
            foreach ($view->iterate($all) as $order) {
                $got[] = $order->number . ':' . implode(',', $this->qtys($order->items)) . ':' . ($order->customer?->name ?? '-');
                $this->assertTrue($order->items === [] || $order->items[0]->product !== null, 'items.product carregado');
            }
            $this->assertEquals($expected, $got, "{$family}: iterate() em páginas de {$size} = find()");
        }

        // Filtro em SQL e filtro avaliado em PHP (fallback): páginas cortadas nas linhas lidas
        $filtered = $shop->orders->with('items');
        $filtered->withBatchSize(2);
        $this->assertEquals(['o1', 'o5'], $this->numbers(iterator_to_array($filtered->iterate(Spec::property('customerId', Spec::equalTo(1))), false)));
        $php = $shop->orders->withInMemoryFallback()->with('items');
        $php->withBatchSize(2);
        $odd = new PredicateSpecification(static fn(PdoShopOrder $o): bool => in_array($o->number, ['o1', 'o3', 'o5'], true));
        $this->assertEquals(['o1', 'o3', 'o5'], $this->numbers(iterator_to_array($php->iterate($odd), false)), "{$family}: fallback em memória com relações");
        $shop->orders->withInMemoryFallback(false);

        // Soft delete fica fora das páginas; ManyToMany também por página
        $shop->posts->remove($shop->posts->find(Spec::property('title', Spec::equalTo('y')))[0]);
        $posts = $shop->posts->with('tags');
        $posts->withBatchSize(1);
        $titles = [];
        foreach ($posts->iterate($all) as $post) {
            $titles[] = $post->title . ':' . implode(',', $this->tagNames($post->tags));
        }
        $this->assertEquals(['x:a,b', 'z:'], $titles, "{$family}: páginas sem o marcado, com as etiquetas");

        // Sem relações: streaming puro como antes (uma consulta, sem LIMIT)
        $log = new InMemoryQueryLogger();
        $shop->orders->withQueryLogger($log);
        $count = 0;
        foreach ($shop->orders->iterate($all) as $_) {
            $count++;
        }
        $this->assertTrue($count === 5 && count($log->statements()) === 1 && !str_contains($log->statements()[0], 'LIMIT'), "{$family}: sem with() continua um cursor só: " . implode(' | ', $log->statements()));
        $shop->orders->withQueryLogger(null);
    }

    /** SQLite rastreado: com relações, nenhum comando roda com outro cursor aberto (find, iterate, findPage). */
    private function testNoOpenCursorWhileLoading(): void
    {
        $pdo = new PdoCursorTrackingPdo('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->loadSchema($pdo, 'sqlite');
        $data = $this->seed($pdo);
        $shop = $data['shop'];
        $shop->tags->putAll([new PdoShopTag(1, 'a')]);
        $shop->posts->putAll([new PdoShopPost('x', [new PdoShopTag(1, 'a')]), new PdoShopPost('y')]);
        $all = Spec::alwaysTrue();

        // Controle positivo: o rastreador vê um comando dentro de um cursor aberto
        $pdo->reset();
        foreach ($shop->orders->iterate($all) as $_) {
            $shop->items->count($all);
            break;
        }
        $this->assertTrue($pdo->violations !== [], 'sqlite rastreado: controle positivo (iterate sem relações é um cursor aberto)');

        $pdo->reset();
        $view = $shop->orders->with('items.product', 'customer');
        $view->withBatchSize(2);
        $n = 0;
        foreach ($view->iterate($all) as $_) {
            $n++;
        }
        $view->find($all);
        $view->findPage($all, 3, 1);
        $posts = $shop->posts->with('tags');
        $posts->withBatchSize(1);
        foreach ($posts->iterate($all) as $_) {
            $n++;
        }
        $this->assertEquals(7, $n);
        $this->assertEquals([], $pdo->violations, 'sqlite rastreado: nenhum comando com cursor aberto ao carregar relações: ' . implode(PHP_EOL, array_slice($pdo->violations, 0, 3)));
    }

    /**
     * MySQL com PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false: iterate() com relações funciona; o controle
     * positivo prova que a conexão é mesmo sem buffer (um comando com cursor aberto falha).
     */
    private function testIterateUnbufferedMySql(): void
    {
        $dsn = getenv('ASPEC_PDO_MYSQL_DSN');
        if ($dsn === false || $dsn === '' || !extension_loaded('pdo_mysql')) {
            return;
        }
        $user = getenv('ASPEC_PDO_MYSQL_USER');
        $password = getenv('ASPEC_PDO_MYSQL_PASSWORD');
        $buffered = $this->connectFromEnvironment('mysql');
        $this->loadSchema($buffered, 'mysql');
        $this->seed($buffered);
        $tags = [new PdoShopTag(1, 'a'), new PdoShopTag(2, 'b')];
        $seedShop = $this->shop($buffered);
        $seedShop->tags->putAll($tags);
        $posts = [];
        for ($i = 1; $i <= 25; $i++) {
            $posts[] = new PdoShopPost('t' . $i, $i % 3 === 0 ? [] : ($i % 2 === 0 ? $tags : [$tags[0]]));
        }
        $seedShop->posts->putAll($posts);

        $pdo = new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]);
        $shop = $this->shop($pdo);
        $all = Spec::alwaysTrue();

        // Controle positivo: sem buffer, um comando com outro cursor aberto falha
        $e = $this->assertThrows(PdoRepositoryException::class, function () use ($shop, $all): void {
            foreach ($shop->orders->iterate($all) as $_) {
                $shop->items->count($all);
            }
        }, 'mysql sem buffer: controle positivo');
        $this->assertTrue(str_contains($e->getMessage(), 'unbuffered') || str_contains($e->getMessage(), '2014'), $e->getMessage());

        $view = $shop->orders->with('items.product', 'customer');
        $view->withBatchSize(2);
        $got = [];
        foreach ($view->iterate($all) as $order) {
            $got[] = $order->number . ':' . implode(',', $this->qtys($order->items)) . ':' . ($order->customer?->name ?? '-');
        }
        $this->assertEquals(['o1:3,7:Ana', 'o2:1:Bob', 'o3::-', 'o4:9:Caio', 'o5:2:Ana'], $got, 'mysql sem buffer: iterate() com relações em páginas de 2');
        $this->assertCount(5, $view->find($all), 'mysql sem buffer: find() com relações');
        $this->assertCount(2, $view->findPage($all, 2, 3), 'mysql sem buffer: findPage() com relações');
        $postView = $shop->posts->with('tags');
        $postView->withBatchSize(4);
        $links = 0;
        $count = 0;
        foreach ($postView->iterate($all) as $post) {
            $links += count($post->tags);
            $count++;
        }
        $this->assertTrue($count === 25 && $links === (int) $this->raw($buffered, 'SELECT COUNT(*) FROM aspec_rel_post_tag'), "mysql sem buffer: ManyToMany por página ({$count} posts, {$links} vínculos)");
        fwrite(STDOUT, "    [D3] MySQL " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . " sem buffer: iterate()/find()/findPage() com relações OK (controle positivo: comando com cursor aberto falha)\n");
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-02 (forward 032): ManyToMany em lote e memória de iterate() com relações
    ///////////////////////////////////////////////////////////////////////////

    private function testManyToManyBenchmark(PDO $pdo, string $family, int $owners = 1000, int $perOwner = 5): void
    {
        $this->reset($pdo);
        $rows = [];
        for ($t = 1; $t <= 50; $t++) {
            $rows[] = sprintf("(%d, 'tag%d')", $t, $t);
        }
        $pdo->exec('INSERT INTO aspec_rel_tag (id, name) VALUES ' . implode(', ', $rows));
        $rows = [];
        for ($p = 1; $p <= $owners; $p++) {
            $rows[] = sprintf("(%d, 'post%d')", $p, $p);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $pdo->exec('INSERT INTO aspec_rel_post (id, title) VALUES ' . implode(', ', $chunk));
        }
        $rows = [];
        for ($p = 1; $p <= $owners; $p++) {
            for ($k = 0; $k < $perOwner; $k++) {
                $rows[] = sprintf('(%d, %d)', $p, 1 + ($p + $k * 7) % 50);
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $pdo->exec('INSERT INTO aspec_rel_post_tag (post_id, tag_id) VALUES ' . implode(', ', $chunk));
        }

        $log = new InMemoryQueryLogger(20000);
        $shop = $this->shop($pdo, $log);
        $all = Spec::alwaysTrue();
        $started = hrtime(true);
        $posts = $shop->posts->with('tags')->find($all);
        $eagerMs = (hrtime(true) - $started) / 1e6;
        $eagerQueries = count($log->statements());
        $linked = array_sum(array_map(static fn(PdoShopPost $p): int => count($p->tags), $posts));
        $this->assertEquals($owners * $perOwner, $linked, "{$family}: {$owners}×{$perOwner} vínculos carregados");
        $this->assertEquals(3, $eagerQueries, "{$family}: RN-02 — donos + junção + alvos = 3 consultas (houve {$eagerQueries})");

        // Sincronização em lote: cada dono troca um vínculo, um putAll()
        foreach ($posts as $post) {
            array_shift($post->tags);
            $post->tags[] = $posts[0]->tags[0] ?? null;
            $post->tags = array_values(array_filter($post->tags));
        }
        $log->clear();
        $started = hrtime(true);
        $shop->posts->putAll($posts);
        $syncMs = (hrtime(true) - $started) / 1e6;
        $syncStatements = count($log->statements());

        $log->clear();
        $started = hrtime(true);
        $naive = 0;
        foreach ($shop->posts->find($all) as $post) {
            $naive += (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_rel_post_tag WHERE post_id = ' . (int) $post->id);
        }
        $naiveMs = (hrtime(true) - $started) / 1e6;

        fwrite(STDOUT, sprintf(
            "    [RN-02] %s, %d donos × %d alvos: with('tags') %d consultas %.0f ms; putAll() sincronizando %d donos %d comandos %.0f ms; laço por dono %d consultas %.0f ms\n",
            $family,
            $owners,
            $perOwner,
            $eagerQueries,
            $eagerMs,
            count($posts),
            $syncStatements,
            $syncMs,
            $owners + 1,
            $naiveMs
        ));
        $this->assertTrue($naive >= $owners, 'o laço ingênuo leu os vínculos');
        $this->reset($pdo);
    }

    /**
     * RN-02: iterate() com relações sobre 10 000 linhas guarda uma página por vez. Mede o pico de
     * memória (memory_reset_peak_usage()) de iterate() com with('tags') em 2 000 e em 10 000 posts
     * (deve ficar no mesmo patamar) contra find() nos 10 000 (cresce com o resultado). Posts e
     * etiquetas não têm referência de volta: sem ciclos, o pico mede o que fica vivo, não o atraso do
     * coletor de ciclos.
     */
    private function testIterateMemory(PDO $pdo, string $family, int $rows = 10000, int $batch = 500): void
    {
        $this->reset($pdo);
        $values = [];
        for ($t = 1; $t <= 20; $t++) {
            $values[] = sprintf("(%d, 'tag%d')", $t, $t);
        }
        $pdo->exec('INSERT INTO aspec_rel_tag (id, name) VALUES ' . implode(', ', $values));
        $values = [];
        $links = [];
        for ($p = 1; $p <= $rows; $p++) {
            $values[] = sprintf("(%d, 'a post with a title of some length, number %d')", $p, $p);
            $links[] = sprintf('(%d, %d)', $p, 1 + $p % 20);
            $links[] = sprintf('(%d, %d)', $p, 1 + ($p + 7) % 20);
        }
        foreach (array_chunk($values, 1000) as $chunk) {
            $pdo->exec('INSERT INTO aspec_rel_post (id, title) VALUES ' . implode(', ', $chunk));
        }
        foreach (array_chunk($links, 1000) as $chunk) {
            $pdo->exec('INSERT INTO aspec_rel_post_tag (post_id, tag_id) VALUES ' . implode(', ', $chunk));
        }
        $shop = $this->shop($pdo);

        $measure = function (callable $read): array {
            gc_collect_cycles();
            $base = memory_get_usage();
            memory_reset_peak_usage();
            $started = hrtime(true);
            $count = $read();
            return [(memory_get_peak_usage() - $base) / 1048576, (hrtime(true) - $started) / 1e6, $count];
        };
        $iterate = function (int $limit) use ($shop, $batch): int {
            $view = $shop->posts->with('tags');
            $view->withBatchSize($batch);
            $linked = 0;
            foreach ($view->iterate(Spec::property('id', Spec::lessThanOrEqualTo($limit))) as $post) {
                $linked += count($post->tags);
            }
            return $linked;
        };
        [$small, $smallMs, $smallLinks] = $measure(fn(): int => $iterate((int) ($rows / 5)));
        [$large, $largeMs, $largeLinks] = $measure(fn(): int => $iterate($rows));
        [$found, $foundMs, $foundLinks] = $measure(function () use ($shop, $rows): int {
            $all = $shop->posts->with('tags')->find(Spec::property('id', Spec::lessThanOrEqualTo($rows)));
            return array_sum(array_map(static fn(PdoShopPost $p): int => count($p->tags), $all));
        });
        $this->assertEquals(2 * $rows / 5, $smallLinks);
        $this->assertEquals(2 * $rows, $largeLinks);
        $this->assertEquals(2 * $rows, $foundLinks);
        $this->assertTrue($large < $small * 1.5 + 0.5, sprintf('%s: RN-02 — iterate() em %d linhas (%.2f MB) no patamar de %d linhas (%.2f MB)', $family, $rows, $large, $rows / 5, $small));
        $this->assertTrue($large * 3 < $found, sprintf('%s: RN-02 — iterate() %.2f MB contra find() %.2f MB', $family, $large, $found));
        fwrite(STDOUT, sprintf(
            "    [RN-02] %s, iterate() com with('tags') em páginas de %d: pico %.2f MB em %d linhas (%.0f ms), %.2f MB em %d linhas (%.0f ms); find() %.2f MB (%.0f ms)\n",
            $family,
            $batch,
            $small,
            $rows / 5,
            $smallMs,
            $large,
            $rows,
            $largeMs,
            $found,
            $foundMs
        ));
        $this->reset($pdo);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Registro (SQLite)
    ///////////////////////////////////////////////////////////////////////////

    private function testRegistry(): void
    {
        $pdo = $this->sqlite();
        $orders = PdoRepository::forEntity($pdo, PdoShopOrder::class);
        $this->assertThrows(InvalidArgumentException::class, fn() => $orders->withRelated(new InMemoryRepository()), 'só PdoRepository (ou decorador dele)');
        $elsewhere = $this->sqlite();
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $orders->withRelated(PdoRepository::forEntity($elsewhere, PdoShopOrderItem::class)), 'composição em outra conexão');
        $this->assertTrue(str_contains($e->getMessage(), 'same PDO connection'), $e->getMessage());

        // Referência (sem cascata) em outra conexão: aceita, carrega
        $remote = PdoRepository::forEntity($elsewhere, PdoShopCustomer::class);
        $remote->put(new PdoShopCustomer(7, 'Remota', 'PT'));
        $pdo->exec("INSERT INTO aspec_rel_customer (id, name, country) VALUES (7, 'Local', 'PT')");
        $orders->withRelated($remote, PdoRepository::forEntity($pdo, PdoShopOrderItem::class));
        $o = new PdoShopOrder('r');
        $o->customerId = 7;
        $orders->put($o);
        $this->assertEquals('Remota', $orders->with('customer')->findById($o->id)?->customer?->name, 'carregada da outra conexão');

        // Sem registro: o pai sem filhos grava; o que precisa da cascata pede o registro
        $lonely = PdoRepository::forEntity($pdo, PdoShopOrder::class);
        $n = new PdoShopOrder('n');
        $lonely->put($n);
        $this->assertTrue($lonely->isLoaded($n, 'items'), 'novo pai sem filhos: coleção conhecida vazia, sem registro');
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $lonely->put(new PdoShopOrder('m', null, [new PdoShopOrderItem(1)])));
        $this->assertTrue(str_contains($e->getMessage(), 'withRelated()'), $e->getMessage());
        $this->assertEquals(0, (int) $this->raw($pdo, "SELECT COUNT(*) FROM aspec_rel_order WHERE number = 'm'"), 'e nada ficou');
        $this->assertThrows(InvalidArgumentException::class, fn() => $lonely->remove($n), 'remover pai com composição pede o registro');

        // ManyToMany (1.14.0): a junção é desta conexão, o repositório do alvo também tem de ser
        $posts = PdoRepository::forEntity($pdo, PdoShopPost::class);
        $e = $this->assertThrows(InvalidArgumentException::class, fn() => $posts->withRelated(PdoRepository::forEntity($elsewhere, PdoShopTag::class)), 'ManyToMany em outra conexão');
        $this->assertTrue(str_contains($e->getMessage(), 'join table') && str_contains($e->getMessage(), 'same PDO connection'), $e->getMessage());
        $this->assertThrows(InvalidArgumentException::class, fn() => $posts->with('tags'), 'with() de ManyToMany sem registro');
        $loose = new PdoShopPost('solta');
        $posts->put($loose);
        $this->assertTrue($posts->isLoaded($loose, 'tags'), 'dono novo sem alvos: sem registro, sem junção');
        $this->assertThrows(InvalidArgumentException::class, fn() => $posts->put(new PdoShopPost('com', [new PdoShopTag(1, 'a')])), 'dono com alvos pede o registro');
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-03: carga ansiosa × laço ingênuo
    ///////////////////////////////////////////////////////////////////////////

    private function testEagerBenchmark(PDO $pdo, string $family, int $parents = 1000, int $children = 10): void
    {
        $this->reset($pdo);
        $pdo->exec("INSERT INTO aspec_rel_product (sku, title) VALUES ('p1', 'Pen'), ('p2', 'Paper')");
        $pdo->exec("INSERT INTO aspec_rel_customer (id, name, country) VALUES (1, 'Ana', 'BR'), (2, 'Bob', 'US')");
        $rows = [];
        for ($o = 1; $o <= $parents; $o++) {
            $rows[] = sprintf("(%d, 'n%d', %d, 1)", $o, $o, 1 + $o % 2);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $pdo->exec('INSERT INTO aspec_rel_order (id, number, customer_id, version) VALUES ' . implode(', ', $chunk));
        }
        $rows = [];
        $item = 0;
        for ($o = 1; $o <= $parents; $o++) {
            for ($c = 1; $c <= $children; $c++) {
                $rows[] = sprintf("(%d, %d, 'p%d', %d)", ++$item, $o, 1 + $c % 2, $c);
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $pdo->exec('INSERT INTO aspec_rel_order_item (id, order_id, product_sku, qty) VALUES ' . implode(', ', $chunk));
        }
        if ($family === 'pgsql') {
            $pdo->exec("SELECT setval(pg_get_serial_sequence('aspec_rel_order', 'id'), (SELECT MAX(id) FROM aspec_rel_order))");
            $pdo->exec("SELECT setval(pg_get_serial_sequence('aspec_rel_order_item', 'id'), (SELECT MAX(id) FROM aspec_rel_order_item))");
        }

        $log = new InMemoryQueryLogger(20000);
        $shop = $this->shop($pdo, $log);
        $all = Spec::alwaysTrue();

        $started = hrtime(true);
        $orders = $shop->orders->with('items')->find($all);
        $eagerMs = (hrtime(true) - $started) / 1e6;
        $eagerQueries = count($log->statements());
        $loadedItems = array_sum(array_map(static fn(PdoShopOrder $o): int => count($o->items), $orders));
        $this->assertEquals($parents * $children, $loadedItems, "{$family}: {$parents}×{$children} carregados");
        $this->assertEquals(2, $eagerQueries, "{$family}: RN-03 — 1 consulta de pedidos + 1 de itens, sem N+1 (houve {$eagerQueries})");

        $log->clear();
        $started = hrtime(true);
        $full = $shop->orders->with('items.product', 'customer')->find($all);
        $fullMs = (hrtime(true) - $started) / 1e6;
        $fullQueries = count($log->statements());
        $this->assertEquals(4, $fullQueries, "{$family}: com items.product e customer: 4 consultas");
        $this->assertTrue($full[0]->items[0]->product !== null && $full[0]->customer !== null);

        $log->clear();
        $started = hrtime(true);
        $iterated = 0;
        foreach ($shop->orders->with('items')->iterate($all) as $order) {
            $iterated += count($order->items);
        }
        $iterateMs = (hrtime(true) - $started) / 1e6;
        $iterateQueries = count($log->statements());
        $this->assertEquals($parents * $children, $iterated);
        $this->assertEquals(2 * (int) ceil($parents / PdoRepository::DEFAULT_BATCH_SIZE), $iterateQueries, "{$family}: iterate() — por lote, uma página de pedidos e uma consulta de itens (1.14.0)");

        // Laço ingênuo: uma consulta de itens por pedido
        $log->clear();
        $started = hrtime(true);
        $naiveItems = 0;
        foreach ($shop->orders->find($all) as $order) {
            $naiveItems += count($shop->items->find(Spec::property('orderId', Spec::equalTo($order->id))));
        }
        $naiveMs = (hrtime(true) - $started) / 1e6;
        $naiveQueries = count($log->statements());
        $this->assertEquals($parents * $children, $naiveItems);
        $this->assertEquals($parents + 1, $naiveQueries);

        fwrite(STDOUT, sprintf(
            "    [RN-03] %s, %d pais × %d filhos: with('items') %d consultas %.0f ms; with('items.product','customer') %d consultas %.0f ms; iterate() %d consultas %.0f ms; laço por pai %d consultas %.0f ms\n",
            $family,
            $parents,
            $children,
            $eagerQueries,
            $eagerMs,
            $fullQueries,
            $fullMs,
            $iterateQueries,
            $iterateMs,
            $naiveQueries,
            $naiveMs
        ));
        $this->reset($pdo);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Apoio
    ///////////////////////////////////////////////////////////////////////////

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $this->loadSchema($pdo, 'sqlite');

        return $pdo;
    }

    private function loadSchema(PDO $pdo, string $family): void
    {
        $sql = (string) file_get_contents(self::SCHEMA_DIR . "/relations.{$family}.sql");
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
    }

    private function connectFromEnvironment(string $family): ?PDO
    {
        $dsn = getenv('ASPEC_PDO_' . strtoupper($family) . '_DSN');
        if ($dsn === false || $dsn === '' || !in_array('pdo_' . $family, array_map('strtolower', get_loaded_extensions()), true)) {
            return null;
        }
        $user = getenv('ASPEC_PDO_' . strtoupper($family) . '_USER');
        $password = getenv('ASPEC_PDO_' . strtoupper($family) . '_PASSWORD');

        return new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** A primeira coluna da primeira linha, crua do driver. */
    private function raw(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value;
    }

    ///////////////////////////////////////////////////////////////////////////
    // README (1.13.0): exemplo 17.3 transcrito (EN/pt-BR); cada comentário "// valor" do README vira uma
    // asserção. Única troca: Customer, Order e OrderLine são ReadmeCustomer, ReadmeOrder e
    // ReadmeOrderLine (tests/Support), escritas iguais; o $pdo e o $log dos exemplos 17 e 17.1 são
    // recriados aqui.
    ///////////////////////////////////////////////////////////////////////////

    /** Os cinco blocos do exemplo 17.3 (três até 1.13.0) são iguais nos dois idiomas, fora os comentários. */
    private function testReadme173BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/docs/en/repositories/pdo-relationships.md', '17.3');
        $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/repositories/pdo-relationships.md', '17.3');
        $this->assertCount(5, $english, 'cinco blocos PHP no exemplo 17.3 (EN)');
        $this->assertCount(5, $portuguese, 'cinco blocos PHP no exemplo 17.3 (pt-BR)');
        foreach ($english as $i => $block) {
            $this->assertEquals(ReadmeBlocks::codeTokens($block), ReadmeBlocks::codeTokens($portuguese[$i]), "README 17.3: bloco {$i} igual fora os comentários");
        }
    }

    /** README 17.3: referência, composição, carga ansiosa contada, whereRelation, órfãos e remoção. */
    private function testReadme173RunsAsWritten(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $log = new InMemoryQueryLogger();

        // --- bloco 1 ---
        $pdo->exec('CREATE TABLE customer (id INTEGER PRIMARY KEY, name VARCHAR(80) NOT NULL)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, number VARCHAR(20) NOT NULL,
    customer_id INTEGER REFERENCES customer (id), deleted_at TEXT)');
        $pdo->exec('CREATE TABLE order_line (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders (id),
    sku VARCHAR(20) NOT NULL, quantity INTEGER NOT NULL)');

        $customers = PdoRepository::forEntity($pdo, ReadmeCustomer::class);
        $lines = PdoRepository::forEntity($pdo, ReadmeOrderLine::class);
        $orders = PdoRepository::forEntity($pdo, ReadmeOrder::class)->withRelated($customers, $lines);

        $customers->putAll([new ReadmeCustomer(1, 'Ana'), new ReadmeCustomer(2, 'Bruno')]);
        foreach (range(1, 100) as $n) {
            $order = new ReadmeOrder("PO-{$n}");
            $order->customer = $customers->findById($n % 2 + 1);
            $order->lines = [new ReadmeOrderLine('KB-1', 1), new ReadmeOrderLine('MS-2', $n)];
            $orders->put($order);
        }
        // Prosa: a chave estrangeira segue a referência; as linhas recebem order_id
        $this->assertEquals(1, $order->customerId, 'README 17.3: customer_id do PO-100 (Ana)');
        $this->assertEquals(200, (int) $pdo->query('SELECT COUNT(*) FROM order_line')->fetchColumn());
        $this->assertEquals(2, (int) $pdo->query('SELECT COUNT(*) FROM order_line WHERE order_id = 100')->fetchColumn());

        // --- bloco 2 ---
        $log->clear();
        foreach ([$orders, $customers, $lines] as $repository) {
            $repository->withQueryLogger($log);
        }
        $all = $orders->with('lines', 'customer')->find(Spec::alwaysTrue());
        $this->assertEquals(100, count($all));
        $this->assertEquals(3, count($log->statements()), 'README 17.3: 3 consultas: ' . implode(' | ', $log->statements()));
        $this->assertEquals('Bruno', $all[0]->customer->name);
        $this->assertTrue($all[0]->customer === $all[2]->customer);
        $this->assertEquals(2, count($all[0]->lines));

        $this->assertEquals(50, $orders->count($orders->whereRelation('customer', Spec::property('name', Spec::equalTo('Ana')))));
        $this->assertEquals(5, $orders->count($orders->whereRelation('lines', Spec::property('quantity', Spec::greaterThan(95)))));

        // --- bloco 3 ---
        $first = $orders->with('lines')->findById(1);
        array_pop($first->lines);
        $orders->put($first);
        $this->assertEquals(1, $lines->count(Spec::property('orderId', Spec::equalTo(1))));

        $bare = $orders->findById(1);
        $this->assertFalse($orders->isLoaded($bare, 'lines'));
        $this->assertTrue($bare->lines === [] && $bare->customer === null, 'README 17.3: sem with() nada carregado');
        $orders->put($bare);
        $this->assertEquals(1, $lines->count(Spec::property('orderId', Spec::equalTo(1))));
        $this->assertEquals(2, (int) $pdo->query('SELECT customer_id FROM orders WHERE id = 1')->fetchColumn(), 'README 17.3: mantém customer_id');

        $orders->remove($first);
        $this->assertEquals(null, $orders->findById(1), 'README 17.3: o pedido está marcado');
        $this->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE id = 1 AND deleted_at IS NOT NULL')->fetchColumn());
        $this->assertEquals(1, $lines->count(Spec::property('orderId', Spec::equalTo(1))));
        $orders->forceRemove($first);
        $this->assertEquals(0, $lines->count(Spec::property('orderId', Spec::equalTo(1))));
        $this->assertEquals(0, (int) $pdo->query('SELECT COUNT(*) FROM orders WHERE id = 1')->fetchColumn(), 'README 17.3: o pedido também se foi');

        // --- bloco 4 (1.14.0): ManyToMany ---
        $pdo->exec('CREATE TABLE tag (id INTEGER PRIMARY KEY, name VARCHAR(30) NOT NULL)');
        $pdo->exec('CREATE TABLE article (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(80) NOT NULL)');
        $pdo->exec('CREATE TABLE article_tag (article_id INTEGER NOT NULL REFERENCES article (id),
    tag_id INTEGER NOT NULL REFERENCES tag (id), PRIMARY KEY (article_id, tag_id))');

        $tags = PdoRepository::forEntity($pdo, ReadmeTag::class);
        $articles = PdoRepository::forEntity($pdo, ReadmeArticle::class)->withRelated($tags);
        [$php, $sql, $orm] = [new ReadmeTag(1, 'php'), new ReadmeTag(2, 'sql'), new ReadmeTag(3, 'orm')];
        $tags->putAll([$php, $sql, $orm]);

        $article = new ReadmeArticle('Specifications over PDO');
        $article->tags = [$php, $sql];
        $articles->put($article);
        $this->assertEquals(2, (int) $pdo->query('SELECT COUNT(*) FROM article_tag WHERE article_id = 1')->fetchColumn(), 'README 17.3: duas linhas de article_tag');

        $log->clear();
        $tags->withQueryLogger($log);
        $articles->withQueryLogger($log);
        $loaded = $articles->with('tags')->findById(1);
        $this->assertEquals(3, count($log->statements()), 'README 17.3: 3 consultas: ' . implode(' | ', $log->statements()));
        $loaded->tags = [$sql, $orm];
        $tagRows = $pdo->query('SELECT id, name FROM tag ORDER BY id')->fetchAll(PDO::FETCH_NUM);
        $articles->put($loaded);
        $this->assertEquals([[1, 2], [1, 3]], $pdo->query('SELECT article_id, tag_id FROM article_tag ORDER BY 1, 2')->fetchAll(PDO::FETCH_NUM), 'README 17.3: desvincula php, vincula orm');
        $this->assertEquals($tagRows, $pdo->query('SELECT id, name FROM tag ORDER BY id')->fetchAll(PDO::FETCH_NUM), 'README 17.3: nenhuma etiqueta gravada');
        $this->assertEquals(1, $articles->count($articles->whereRelation('tags', Spec::property('name', Spec::equalTo('orm')))));

        // --- bloco 5 (1.14.0): markLoaded() e iterate() com relações ---
        $edited = $articles->findById(1);
        $this->assertFalse($articles->isLoaded($edited, 'tags'), 'README 17.3: sem with() não carregada');
        $edited->tags = [$php];
        $articles->markLoaded($edited, 'tags');
        $articles->put($edited);
        $this->assertEquals([[1, 1]], $pdo->query('SELECT article_id, tag_id FROM article_tag ORDER BY 1, 2')->fetchAll(PDO::FETCH_NUM), 'README 17.3: vincula php, desvincula sql e orm');
        $this->assertEquals(1, count($articles->with('tags')->findById(1)->tags));

        $log->clear();
        $view = $articles->with('tags')->withBatchSize(500);
        $names = [];
        foreach ($view->iterate(Spec::alwaysTrue()) as $one) {
            $names[] = $one->tags[0]->name;
        }
        $this->assertEquals(['php'], $names, 'README 17.3: iterate() com relações');
        $this->assertTrue(str_contains($log->statements()[0], 'LIMIT 501'), 'README 17.3: páginas de 500 por id: ' . $log->statements()[0]);
        $this->assertEquals(3, (int) $pdo->query('SELECT COUNT(*) FROM tag')->fetchColumn(), 'README 17.3: as etiquetas ficam');
    }
}
