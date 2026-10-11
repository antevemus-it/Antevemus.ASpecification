<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Entities\AbstractUlidEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use DateTimeImmutable;

/**
 * Contract battery entity of the PDO repository (forward 028): mapped with attributes, ULID identity
 * declared by the parent (class-level #[Id]), every column type the battery compares. The same
 * instances are stored in InMemoryRepository and in PdoRepository (RN-02).
 *
 * @version 1.11.0
 */
#[Table('aspec_article')]
#[Id(strategy: IdStrategy::Ulid, column: 'id', property: 'entityId')]
class PdoArticle extends AbstractUlidEntity
{
    public static int $constructed = 0;

    #[Column('title')]
    private string $title;

    #[Column('status')]
    private PdoArticleStatus $status;

    #[Column('views')]
    private int $views;

    #[Column('price', type: ColumnType::Decimal)]
    private string $price;

    #[Column('published')]
    private bool $published;

    #[Column('published_at')]
    private ?DateTimeImmutable $publishedAt;

    #[Column('tags')]
    private array $tags;

    #[Column('note')]
    private ?string $note;

    /**
     * @param list<string> $tags
     */
    public function __construct(
        string $title,
        PdoArticleStatus $status = PdoArticleStatus::Draft,
        int $views = 0,
        string $price = '0.00',
        bool $published = false,
        ?DateTimeImmutable $publishedAt = null,
        array $tags = [],
        ?string $note = null
    ) {
        parent::__construct();
        self::$constructed++;
        $this->title = $title;
        $this->status = $status;
        $this->views = $views;
        $this->price = $price;
        $this->published = $published;
        $this->publishedAt = $publishedAt;
        $this->tags = $tags;
        $this->note = $note;
    }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function getStatus(): PdoArticleStatus { return $this->status; }
    public function setStatus(PdoArticleStatus $status): void { $this->status = $status; }
    public function getViews(): int { return $this->views; }
    public function setViews(int $views): void { $this->views = $views; }
    public function getPrice(): string { return $this->price; }
    public function isPublished(): bool { return $this->published; }
    public function getPublishedAt(): ?DateTimeImmutable { return $this->publishedAt; }
    /** @return list<string> */
    public function getTags(): array { return $this->tags; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): void { $this->note = $note; }
}
