<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

/**
 * FileNameSanitizer - Cross-platform file name sanitizer for entity identifiers
 *
 * Converts entity identifiers into safe file names across Windows, Linux, and macOS
 * filesystems, cleanly and reversibly preserving UUIDs, integers, and alphanumeric strings.
 *
 * Features:
 * - Sanitization of reserved filesystem characters (/ \\ : * ? \" < > |)
 * - Reversible de-sanitization back to original identifier
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FileNameSanitizer
{
    /**
     * Sanitizes an entity ID for safe usage as a file name.
     *
     * @param string|int $id
     * @return string
     */
    public static function sanitize(string|int $id): string
    {
        $strId = (string)$id;

        // Standard alphanumeric, hyphen, underscore, or dot (e.g., UUID, integers, slugs)
        if (preg_match("/^[a-zA-Z0-9_.-]+$/", $strId)) {
            return $strId;
        }

        // Safe replacement of reserved characters by hex representation
        return preg_replace_callback("/[^a-zA-Z0-9_.-]/", function (array $matches): string {
            return "~" . bin2hex($matches[0]);
        }, $strId) ?? $strId;
    }

    /**
     * Reverts sanitization to recover the original entity ID.
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
