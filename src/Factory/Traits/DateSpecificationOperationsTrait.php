<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;
use DateTimeInterface;

/**
 * DateSpecificationOperationsTrait - Trait agregador de operações temporais e comparação de datas (IDateSpecificationFactory).
 *
 * Funcionalidades:
 * - Validação de datas absolutas e relativas (isToday, isPast, isFuture)
 * - Comparações temporais (before, after, between, atTheSameTimeAs)
 * - Parsing flexível de datas em string
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait DateSpecificationOperationsTrait
{
    /**
     * Cria especificação que verifica se data é anterior a uma string.
     *
     * Converte string para DateTime usando formato especificado ou formatos comuns.
     *
     * @param string $dateString String de data
     * @param string|null $format Formato da data (padrão: tenta formatos comuns)
     * @return ISpecification<\DateTimeInterface> Especificação de data anterior
     * @throws \InvalidArgumentException Se a string não puder ser convertida para data
     */
    public function beforeString(string $dateString, ?string $format = null): ISpecification
    {
        return $this->dateFactory->beforeString($dateString, $format);
    }

    /**
     * Cria especificação que verifica se data é posterior a uma string.
     *
     * @param string $dateString String de data
     * @param string|null $format Formato da data (padrão: tenta formatos comuns)
     * @return ISpecification<\DateTimeInterface> Especificação de data posterior
     * @throws \InvalidArgumentException Se a string não puder ser convertida para data
     */
    public function afterString(string $dateString, ?string $format = null): ISpecification
    {
        return $this->dateFactory->afterString($dateString, $format);
    }

    /**
     * Cria especificação que verifica se data é exatamente igual.
     *
     * @param \DateTimeInterface $date Data para comparação
     * @return ISpecification<\DateTimeInterface> Especificação de igualdade de data
     */
    public function at(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->at($date);
    }

    /**
     * Alias para at().
     *
     * @param \DateTimeInterface $date Data para comparação
     * @return ISpecification<\DateTimeInterface>
     */
    public function atTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->atTheSameTimeAs($date);
    }

    /**
     * Cria especificação que verifica se data é anterior ou igual.
     *
     * @param \DateTimeInterface $date Data limite (inclusiva)
     * @return ISpecification<\DateTimeInterface> Especificação de data anterior ou igual
     */
    public function beforeOrAt(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->beforeOrAt($date);
    }

    /**
     * Alias para beforeOrAt().
     *
     * @param \DateTimeInterface $date Data limite (inclusiva)
     * @return ISpecification<\DateTimeInterface>
     */
    public function beforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->beforeOrAtTheSameTimeAs($date);
    }

    /**
     * Alias para beforeOrAt(). Verifica se a data é anterior ou igual à data limite.
     *
     * @param \DateTimeInterface  Data limite
     * @return ISpecification
     */
    public function isBeforeOrAt(DateTimeInterface $date): ISpecification
    {
        return $this->beforeOrAt($date);
    }

    /**
     * Alias para beforeOrAt(). Verifica se a data é anterior ou igual à data limite.
     *
     * @param \DateTimeInterface  Data limite
     * @return ISpecification
     */
    public function isBeforeOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->beforeOrAtTheSameTimeAs($date);
    }

    /**
     * Alias para at(). Verifica se a data é exatamente no mesmo instante temporal.
     *
     * @param \DateTimeInterface  Data para comparação
     * @return ISpecification
     */
    public function isAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->atTheSameTimeAs($date);
    }

    /**
     * Alias para afterOrAt(). Verifica se a data é posterior ou igual à data limite.
     *
     * @param \DateTimeInterface  Data limite
     * @return ISpecification
     */
    public function isAfterOrAt(DateTimeInterface $date): ISpecification
    {
        return $this->afterOrAt($date);
    }

    /**
     * Alias para afterOrAt(). Verifica se a data é posterior ou igual à data limite.
     *
     * @param \DateTimeInterface  Data limite
     * @return ISpecification
     */
    public function isAfterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->afterOrAtTheSameTimeAs($date);
    }

    /**
     * Cria especificação que verifica se data é anterior ou igual a uma string.
     *
     * @param string $dateString String de data
     * @param string|null $format Formato da data (padrão: tenta formatos comuns)
     * @return ISpecification<\DateTimeInterface> Especificação de data anterior ou igual
     */
    public function beforeOrAtString(string $dateString, ?string $format = null): ISpecification
    {
        return $this->dateFactory->beforeOrAtString($dateString, $format);
    }

    /**
     * Cria especificação que verifica se data é posterior ou igual.
     *
     * @param \DateTimeInterface $date Data limite (inclusiva)
     * @return ISpecification<\DateTimeInterface> Especificação de data posterior ou igual
     */
    public function afterOrAt(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->afterOrAt($date);
    }

    /**
     * Alias para afterOrAt().
     *
     * @param \DateTimeInterface $date Data limite (inclusiva)
     * @return ISpecification<\DateTimeInterface>
     */
    public function afterOrAtTheSameTimeAs(DateTimeInterface $date): ISpecification
    {
        return $this->dateFactory->afterOrAtTheSameTimeAs($date);
    }

    /**
     * Cria especificação que verifica se data é posterior ou igual a uma string.
     *
     * @param string $dateString String de data
     * @param string|null $format Formato da data (padrão: tenta formatos comuns)
     * @return ISpecification<\DateTimeInterface> Especificação de data posterior ou igual
     */
    public function afterOrAtString(string $dateString, ?string $format = null): ISpecification
    {
        return $this->dateFactory->afterOrAtString($dateString, $format);
    }

    /**
     * Cria especificação que verifica se data está entre dois limites.
     *
     * @param \DateTimeInterface $start Data inicial (inclusiva)
     * @param \DateTimeInterface $end Data final (inclusiva)
     * @return ISpecification<\DateTimeInterface> Especificação de intervalo de data
     */
    public function between(DateTimeInterface $start, DateTimeInterface $end): ISpecification
    {
        return $this->dateFactory->between($start, $end);
    }

    /**
     * Cria especificação que verifica se data é hoje.
     *
     * Compara apenas a data (ano, mês, dia), ignorando hora/minuto/segundo.
     *
     * @return ISpecification<\DateTimeInterface> Especificação de data atual
     */
    public function isToday(): ISpecification
    {
        return $this->dateFactory->isToday();
    }

    /**
     * Cria especificação que verifica se data é no passado.
     *
     * @return ISpecification<\DateTimeInterface> Especificação de data passada
     */
    public function isPast(): ISpecification
    {
        return $this->dateFactory->isPast();
    }

    /**
     * Cria especificação que verifica se data é no futuro.
     *
     * @return ISpecification<\DateTimeInterface> Especificação de data futura
     */
    public function isFuture(): ISpecification
    {
        return $this->dateFactory->isFuture();
    }

}
