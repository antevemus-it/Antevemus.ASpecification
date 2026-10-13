<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Exceptions;

use RuntimeException;

/**
 * MissingExtensionException - An optional PHP extension needed by the requested feature is not loaded.
 *
 * Raised when a specification is built with an option whose evaluation depends on an extension
 * the library only suggests (2.1.0: `TextComparison::IgnoreCaseAndAccents` needs `ext-intl`). It is
 * raised when the specification is built, never while a candidate is evaluated, so the gap shows
 * where the rule is written.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MissingExtensionException extends RuntimeException
{
    /**
     * @param string $extension Name of the missing extension, as `php -m` lists it (e.g. 'intl')
     * @param string $reason What needs it and how to proceed
     */
    public function __construct(
        public readonly string $extension,
        string $reason = ''
    ) {
        parent::__construct(sprintf(
            'The PHP extension "%s" (ext-%s) is not loaded%s.',
            $extension,
            $extension,
            $reason !== '' ? ": {$reason}" : ''
        ));
    }
}
