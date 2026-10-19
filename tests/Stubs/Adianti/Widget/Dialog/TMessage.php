<?php

declare(strict_types=1);

namespace Adianti\Widget\Dialog;

/**
 * TMessage - Stub de compatibilidade do Adianti Framework para testes (forward 048, D33)
 *
 * Mesma assinatura do construtor do real (lib/adianti/widget/dialog/TMessage.php, 8.7:
 * `__construct($type, $message, ?TAction $action = NULL, $title_msg = '')`); em vez de abrir a janela, guarda
 * o que seria mostrado em $shown (só do stub).
 *
 * @version    8.7
 * @package    widget
 * @author     Pablo Dall'Oglio
 * @license    https://adiantiframework.com.br/license
 */
class TMessage
{
    /** @var list<array{type: string, message: string, title: string}> Só do stub */
    public static array $shown = [];

    public function __construct($type, $message, $action = null, $title_msg = '')
    {
        self::$shown[] = ['type' => (string) $type, 'message' => (string) $message, 'title' => (string) $title_msg];
    }
}
