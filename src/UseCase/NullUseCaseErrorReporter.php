<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

use Throwable;

/**
 * NullUseCaseErrorReporter - Default Reporter That Discards
 *
 * Default of the UseCaseRunner (forward 024, D6). Replace it with a reporter that logs.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class NullUseCaseErrorReporter implements IUseCaseErrorReporter
{
    public function report(Throwable $error, IUseCase $useCase, object $request): void
    {
    }
}
