<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

/**
 * FileNameSanitizer - Sanitizador de identificadores para nomes de arquivo cross-platform
 *
 * Converte identificadores de entidades em nomes de arquivos seguros para sistemas
 * de arquivos Windows, Linux e macOS, preservando UUIDs, inteiros e strings
 * alfanuméricas de forma limpa e reversível.
 *
 * Funcionalidades:
 * - Sanitização de caracteres reservados (/ \\ : * ? \" < > |)
 * - Dessanitização reversível
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FileNameSanitizer
{
    /**
     * Sanitiza um ID de entidade para uso seguro como nome de arquivo.
     *
     * @param string|int $id
     * @return string
     */
    public static function sanitize(string|int $id): string
    {
        $strId = (string)$id;

        // Se for alfanumérico padrão, traço, underscore ou ponto (ex: UUID, inteiros, slugs)
        if (preg_match("/^[a-zA-Z0-9_.-]+$/", $strId)) {
            return $strId;
        }

        // Substituição segura de caracteres reservados por representação hex
        return preg_replace_callback("/[^a-zA-Z0-9_.-]/", function (array $matches): string {
            return "~" . bin2hex($matches[0]);
        }, $strId) ?? $strId;
    }

    /**
     * Reverte a sanitização para recuperar o ID original da entidade.
     *
     * @param string $sanitized
     * @return string
     */
    public static function desanitize(string $sanitized): string
    {
        if (!str_contains($sanitized, "~")) {
            return $sanitized;
        }

        return preg_replace_callback("/~([a-fA-F0-9]{2})/", function (array $matches): string {
            $hex = hex2bin($matches[1]);
            return $hex !== false ? $hex : $matches[0];
        }, $sanitized) ?? $sanitized;
    }
}
