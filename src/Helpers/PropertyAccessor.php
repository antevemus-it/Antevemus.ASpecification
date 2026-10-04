<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use ArrayAccess;
use Closure;

/**
 * PropertyAccessor - Extrator polimórfico e resiliente de propriedades
 *
 * Provê resolução unificada e reflexiva de propriedades, atributos, métodos
 * e navegação aninhada (dot notation), compatível com DTOs, entidades ricas e arrays.
 * Inspirado nas melhores práticas do ALinqPropertyAccess.
 *
 * Funcionalidades:
 * - Acesso a atributos públicos e mágicos
 * - Acesso a métodos getter (getProperty, property) e predicados booleanos (isProperty, hasProperty)
 * - Suporte a arrays e instâncias de ArrayAccess
 * - Navegação aninhada por dot notation (ex: 'user.address.city')
 * - Geração de Closures otimizados para pipelines funcionais e LINQ
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PropertyAccessor
{
    /**
     * Obtém o valor de uma propriedade ou caminho aninhado a partir de um objeto ou array.
     *
     * @param mixed $target Objeto ou array candidato
     * @param string $property Nome da propriedade ou caminho dot notation ('user.address.city')
     * @return mixed Valor extraído ou null se não resolvido
     */
    public static function getValue(mixed $target, string $property): mixed
    {
        if ($target === null) {
            return null;
        }

        if (str_contains($property, '.')) {
            return self::getNestedValue($target, $property);
        }

        return self::extractSingleProperty($target, $property);
    }

    /**
     * Verifica se a propriedade ou caminho dot notation existe no alvo.
     *
     * @param mixed $target
     * @param string $property
     * @return bool
     */
    public static function hasProperty(mixed $target, string $property): bool
    {
        if ($target === null) {
            return false;
        }

        if (str_contains($property, '.')) {
            $segments = explode('.', $property);
            $current = $target;
            foreach ($segments as $segment) {
                if ($current === null || !self::hasSingleProperty($current, $segment)) {
                    return false;
                }
                $current = self::extractSingleProperty($current, $segment);
            }
            return true;
        }

        return self::hasSingleProperty($target, $property);
    }

    /**
     * Extrai propriedade única de um objeto ou array.
     *
     * @param mixed $target
     * @param string $property
     * @return mixed
     */
    private static function extractSingleProperty(mixed $target, string $property): mixed
    {
        if (is_array($target) || $target instanceof ArrayAccess) {
            return $target[$property] ?? null;
        }

        if (is_object($target)) {
            $methods = [
                'get' . ucfirst($property),
                $property,
                'is' . ucfirst($property),
                'has' . ucfirst($property),
            ];

            foreach ($methods as $method) {
                if (method_exists($target, $method) && is_callable([$target, $method])) {
                    return $target->$method();
                }
            }

            if (isset($target->{$property})) {
                return $target->{$property};
            }

            if (property_exists($target, $property)) {
                try {
                    $ref = new \ReflectionProperty($target, $property);
                    if ($ref->isPublic()) {
                        return $ref->getValue($target);
                    }
                } catch (\Throwable) {
                    // ignora
                }
            }
        }

        return null;
    }

    /**
     * Verifica se uma propriedade individual existe no objeto ou array.
     *
     * @param mixed $target
     * @param string $property
     * @return bool
     */
    private static function hasSingleProperty(mixed $target, string $property): bool
    {
        if (is_array($target) || $target instanceof ArrayAccess) {
            return array_key_exists($property, (array)$target) || isset($target[$property]);
        }

        if (is_object($target)) {
            $methods = [
                'get' . ucfirst($property),
                $property,
                'is' . ucfirst($property),
                'has' . ucfirst($property),
            ];

            foreach ($methods as $method) {
                if (method_exists($target, $method) && is_callable([$target, $method])) {
                    return true;
                }
            }

            if (isset($target->{$property})) {
                return true;
            }

            if (property_exists($target, $property)) {
                try {
                    $ref = new \ReflectionProperty($target, $property);
                    return $ref->isPublic();
                } catch (\Throwable) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Extrai valor navegando por segmentos separados por ponto.
     *
     * @param mixed $target
     * @param string $path
     * @return mixed
     */
    private static function getNestedValue(mixed $target, string $path): mixed
    {
        $current = $target;
        foreach (explode('.', $path) as $segment) {
            if ($current === null) {
                return null;
            }
            $current = self::extractSingleProperty($current, $segment);
        }
        return $current;
    }

    /**
     * Retorna um Closure que extrai a propriedade indicada de qualquer candidato.
     *
     * @param string $property
     * @return Closure(mixed): mixed
     */
    public static function getAccessor(string $property): Closure
    {
        return fn(mixed $item): mixed => self::getValue($item, $property);
    }
}
