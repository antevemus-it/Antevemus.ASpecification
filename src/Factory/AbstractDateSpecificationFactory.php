<?php

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\IDateSpecificationFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractDateSpecificationFactory class.
 *
 * Classe abstrata base para fábricas de especificações de data.
 *
 * Fornece implementações padrão para métodos alias e helpers para
 * parsing de strings de data em múltiplos formatos comuns do PHP.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractDateSpecificationFactory implements IDateSpecificationFactory
{
    /**
     * Formatos de data comuns para tentar fazer parse.
     *
     * @var array<string>
     */
    protected array $commonDateFormats = [
        'Y-m-d',           // 2025-01-15
        'd/m/Y',           // 15/01/2025
        'd-m-Y',           // 15-01-2025
        'd.m.Y',           // 15.01.2025
        'Y/m/d',           // 2025/01/15
        'Ymd',             // 20250115
        'Y-m-d H:i:s',     // 2025-01-15 14:30:00
        'd/m/Y H:i:s',     // 15/01/2025 14:30:00
        \DateTime::ATOM,   // 2025-01-15T14:30:00+00:00
        \DateTime::ISO8601,
        \DateTime::RFC3339,
    ];

    /**
     * Converte string de data para DateTime.
     *
     * Método auxiliar que tenta fazer parse usando o formato especificado
     * ou tentando múltiplos formatos comuns.
     *
     * @param string $dateString String de data
     * @param string|null $format Formato específico ou null para tentar formatos comuns
     * @return \DateTimeImmutable Data parseada
     * @throws \InvalidArgumentException Se não conseguir fazer parse da data
     */
    protected function parseDateString(string $dateString, ?string $format = null): \DateTimeImmutable
    {
        if (empty($dateString)) {
            throw new \InvalidArgumentException('Date string cannot be empty');
        }

        // Se formato específico foi fornecido, usar apenas ele
        if ($format !== null) {
            $date = \DateTimeImmutable::createFromFormat($format, $dateString);
            if ($date === false) {
                throw new \InvalidArgumentException(
                    "Unable to parse date string '{$dateString}' with format '{$format}'"
                );
            }
            return $date;
        }

        // Tentar formatos comuns
        foreach ($this->commonDateFormats as $tryFormat) {
            $date = \DateTimeImmutable::createFromFormat($tryFormat, $dateString);
            if ($date !== false) {
                return $date;
            }
        }

        // Última tentativa: construtor padrão do DateTime (aceita muitos formatos)
        try {
            return new \DateTimeImmutable($dateString);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Unable to parse date string '{$dateString}'. Tried common formats and strtotime.",
                0,
                $e
            );
        }
    }

    /**
     * Valida um objeto DateTime.
     *
     * @param \DateTimeInterface $date Data a validar
     * @throws \InvalidArgumentException Se a data for null
     */
    protected function validateDate(\DateTimeInterface $date): void
    {
        if ($date === null) {
            throw new \InvalidArgumentException('Date cannot be null');
        }
    }

    /**
     * {@inheritdoc}
     */
    abstract public function before(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function beforeString(string $dateString, ?string $format = null): ISpecification
    {
        $date = $this->parseDateString($dateString, $format);
        return $this->before($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function after(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function afterString(string $dateString, ?string $format = null): ISpecification
    {
        $date = $this->parseDateString($dateString, $format);
        return $this->after($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function at(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function atTheSameTimeAs(\DateTimeInterface $date): ISpecification
    {
        return $this->at($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function beforeOrAt(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function beforeOrAtTheSameTimeAs(\DateTimeInterface $date): ISpecification
    {
        return $this->beforeOrAt($date);
    }

    /**
     * {@inheritdoc}
     */
    public function beforeOrAtString(string $dateString, ?string $format = null): ISpecification
    {
        $date = $this->parseDateString($dateString, $format);
        return $this->beforeOrAt($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function afterOrAt(\DateTimeInterface $date): ISpecification;

    /**
     * {@inheritdoc}
     */
    public function afterOrAtTheSameTimeAs(\DateTimeInterface $date): ISpecification
    {
        return $this->afterOrAt($date);
    }

    /**
     * {@inheritdoc}
     */
    public function afterOrAtString(string $dateString, ?string $format = null): ISpecification
    {
        $date = $this->parseDateString($dateString, $format);
        return $this->afterOrAt($date);
    }

    /**
     * {@inheritdoc}
     */
    abstract public function between(\DateTimeInterface $start, \DateTimeInterface $end): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isToday(): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isPast(): ISpecification;

    /**
     * {@inheritdoc}
     */
    abstract public function isFuture(): ISpecification;
}
