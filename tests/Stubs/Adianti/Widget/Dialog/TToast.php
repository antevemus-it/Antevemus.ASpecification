<?php

declare(strict_types=1);

namespace Adianti\Widget\Dialog;

/**
 * TToast - Stub de compatibilidade do Adianti Framework para testes (forward 048, D33)
 *
 * Mesma assinatura do real (lib/adianti/widget/dialog/TToast.php, 8.7:
 * `show($type, $message, $place = 'bottom center', $icon = null)`); guarda o que seria mostrado em $shown
 * (só do stub).
 *
 * @version    8.7
 * @package    widget
 * @author     Pablo Dall'Oglio
 * @license    https://adiantiframework.com.br/license
 */
class TToast
{
    /** @var list<array{type: string, message: string}> Só do stub */
    public static array $shown = [];

    public static function show($type, $message, $place = 'bottom center', $icon = null)
    {
        self::$shown[] = ['type' => (string) $type, 'message' => (string) $message];
    }
}
