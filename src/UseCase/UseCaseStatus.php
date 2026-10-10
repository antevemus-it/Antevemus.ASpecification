<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

/**
 * UseCaseStatus - Closed Domain Status of a Use Case Result
 *
 * Ten domain statuses (forward 024, D2), never HTTP codes: success is derived from the status only
 * (isSuccess() is true for SUCCESS, CREATED, ACCEPTED and NO_CHANGE). The HTTP mapping lives in a
 * single edge method, toHttpStatus(), with a closed map (D5); there is no way back from an integer
 * to a status, so a foreign status code can never become a value that does not exist.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum UseCaseStatus: string
{
    /** Done. */
    case SUCCESS = 'success';

    /** Done, and something new was created. */
    case CREATED = 'created';

    /** Accepted for later processing (nothing done yet). */
    case ACCEPTED = 'accepted';

    /** Nothing to do: the requested state already holds (idempotent). */
    case NO_CHANGE = 'no_change';

    /** The request itself did not pass validation. */
    case INVALID = 'invalid';

    /** A business rule refused the request (guard, approval limit). */
    case REJECTED = 'rejected';

    /** The target does not exist. */
    case NOT_FOUND = 'not_found';

    /** The current state or version is incompatible with the request. */
    case CONFLICT = 'conflict';

    /** The caller is not allowed to do this. */
    case FORBIDDEN = 'forbidden';

    /** Unexpected error (no detail of it ever reaches the result). */
    case FAILED = 'failed';

    /** True for SUCCESS, CREATED, ACCEPTED and NO_CHANGE. */
    public function isSuccess(): bool
    {
        return match ($this) {
            self::SUCCESS, self::CREATED, self::ACCEPTED, self::NO_CHANGE => true,
            default => false,
        };
    }

    /**
     * HTTP status of the edge (D5), from a closed map: 200, 201, 202, 204, 400, 422, 404, 409,
     * 403, 500. Nothing else in the library knows HTTP.
     */
    public function toHttpStatus(): int
    {
        return match ($this) {
            self::SUCCESS => 200,
            self::CREATED => 201,
            self::ACCEPTED => 202,
            self::NO_CHANGE => 204,
            self::INVALID => 400,
            self::REJECTED => 422,
            self::NOT_FOUND => 404,
            self::CONFLICT => 409,
            self::FORBIDDEN => 403,
            self::FAILED => 500,
        };
    }
}
