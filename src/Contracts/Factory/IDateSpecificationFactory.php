<?php

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IDateSpecificationFactory interface.
 *
 * Contrato para fábricas que criam especificações de data/hora.
 *
 * Esta interface fornece métodos para criar especificações que operam sobre
 * objetos DateTime/DateTimeImmutable, permitindo comparações temporais e
 * validações de intervalos de data.
 *
 * Diferente do Java original que trabalha com strings de data via SimpleDateFormat,
 * esta abordagem PHP usa tipos nativos DateTime/DateTimeImmutable para type-safety.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDateSpecificationFactory extends ISpecificationFactory
{
    /**
     * Cria especificação que verifica se data é anterior (before).
     *
     * Exemplo:
     * <code>
     * $spec = $factory->before(new DateTime('2025-12-31'));
     * $spec->isSatisfiedBy(new DateTime('2025-01-01')); // true
     * $spec->isSatisfiedBy(new DateTime('2026-01-01')); // false
     * </code>
     *
     * @param \DateTimeInterface $date Data limite (exclusiva)
     * @return ISpecification<\DateTimeInterface> Especificação de data anterior
     */
    public function before(\DateTimeInterface $date): ISpecification;

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
    public function beforeString(string $dateString, ?string $format = null): ISpecification;

    /**
     * Cria especificação que verifica se data é posterior (after).
     *
     * Exemplo:
     * <code>
     * $spec = $factory->after(new DateTime('2025-01-01'));
     * $spec->isSatisfiedBy(new DateTime('2025-12-31')); // true
     * $spec->isSatisfiedBy(new DateTime('2024-12-31')); // false
     * </code>
     *
     * @param \DateTimeInterface $date Data limite (exclusiva)
     * @return ISpecification<\DateTimeInterface> Especificação de data posterior
     */
    public function after(\DateTimeInterface $date): ISpecification;

    /**
     * Cria especificação que verifica se data é posterior a uma string.
     *
     * @param string $dateString String de data
     * @param string|null $format Formato da data (padrão: tenta formatos comuns)
     * @return ISpecification<\DateTimeInterface> Especificação de data posterior
     * @throws \InvalidArgumentException Se a string não puder ser convertida para data
     */
    public function afterString(string $dateString, ?string $format = null): ISpecification;

    /**
     * Cria especificação que verifica se data é exatamente igual.
     *
     * @param \DateTimeInterface $date Data para comparação
     * @return ISpecification<\DateTimeInterface> Especificação de igualdade de data
     */
    public function at(\DateTimeInterface $date): ISpecification;

    /**
     * Alias para at().
     *
     * @param \DateTimeInterface $date Data para comparação
     * @return ISpecification<\DateTimeInterface>
     */
    public function atTheSameTimeAs(\DateTimeInterface $date): ISpecification;

    /**
     * Cria especificação que verifica se data é anterior ou igual.
     *
     * @param \DateTimeInterface $date Data limite (inclusiva)
     * @return ISpecification<\DateTimeInterface> Especificação de data anterior ou igual
     */
    public function beforeOrAt(\DateTimeInterface $date): ISpecification;

    /**
     * Alias para beforeOrAt().
     *
     * @param \DateTimeInterface $date Data limite (inclusiva)
     * @return ISpecification<\DateTimeInterface>
     */
    public function beforeOrAtTheSameTimeAs(\DateTimeInterface $date): ISpecification;

    /**
     * Cria especificação que verifica se data é anterior ou igual a uma string.
     *
     * @param string $dateString String de data
     * @param string|null $format Formato da data (padrão: tenta formatos comuns)
     * @return ISpecification<\DateTimeInterface> Especificação de data anterior ou igual
     */
    public function beforeOrAtString(string $dateString, ?string $format = null): ISpecification;

    /**
     * Cria especificação que verifica se data é posterior ou igual.
     *
     * @param \DateTimeInterface $date Data limite (inclusiva)
     * @return ISpecification<\DateTimeInterface> Especificação de data posterior ou igual
     */
    public function afterOrAt(\DateTimeInterface $date): ISpecification;

    /**
     * Alias para afterOrAt().
     *
     * @param \DateTimeInterface $date Data limite (inclusiva)
     * @return ISpecification<\DateTimeInterface>
     */
    public function afterOrAtTheSameTimeAs(\DateTimeInterface $date): ISpecification;

    /**
     * Cria especificação que verifica se data é posterior ou igual a uma string.
     *
     * @param string $dateString String de data
     * @param string|null $format Formato da data (padrão: tenta formatos comuns)
     * @return ISpecification<\DateTimeInterface> Especificação de data posterior ou igual
     */
    public function afterOrAtString(string $dateString, ?string $format = null): ISpecification;

    /**
     * Cria especificação que verifica se data está entre dois limites.
     *
     * @param \DateTimeInterface $start Data inicial (inclusiva)
     * @param \DateTimeInterface $end Data final (inclusiva)
     * @return ISpecification<\DateTimeInterface> Especificação de intervalo de data
     */
    public function between(\DateTimeInterface $start, \DateTimeInterface $end): ISpecification;

    /**
     * Cria especificação que verifica se data é hoje.
     *
     * Compara apenas a data (ano, mês, dia), ignorando hora/minuto/segundo.
     *
     * @return ISpecification<\DateTimeInterface> Especificação de data atual
     */
    public function isToday(): ISpecification;

    /**
     * Cria especificação que verifica se data é no passado.
     *
     * @return ISpecification<\DateTimeInterface> Especificação de data passada
     */
    public function isPast(): ISpecification;

    /**
     * Cria especificação que verifica se data é no futuro.
     *
     * @return ISpecification<\DateTimeInterface> Especificação de data futura
     */
    public function isFuture(): ISpecification;
}
