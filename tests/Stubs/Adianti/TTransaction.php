<?php

declare(strict_types=1);

namespace Adianti\Database;

use PDO;

/**
 * TTransaction - Stub de compatibilidade do Adianti Framework para testes (forward 048, D33)
 *
 * Espelha o que a receita do README (exemplo 22.3) precisa do real (lib/adianti/database/TTransaction.php,
 * 8.1 a 8.7): open() SEMPRE empilha e abre uma conexão nova, com transação; get() devolve a do topo (null
 * sem nenhuma); close() confirma e desempilha o topo; rollback() desfaz e desempilha o topo. O real lê a
 * conexão de app/config/<database>.php por TConnection; o stub a lê de register() (só do stub), um banco
 * SQLite por nome de arquivo.
 *
 * @version    8.7
 * @package    database
 * @author     Pablo Dall'Oglio
 * @license    https://adiantiframework.com.br/license
 */
class TTransaction
{
    /** @var list<PDO> */
    private static array $conn = [];

    /** @var list<array<string, mixed>> */
    private static array $dbinfo = [];

    /** @var array<string, array<string, mixed>> Só do stub: o que app/config/<database>.php daria */
    private static array $databases = [];

    private function __construct()
    {
    }

    /** Só do stub: registra um banco (['type' => 'sqlite', 'name' => arquivo, 'prep' => '1']). */
    public static function register(string $database, array $dbinfo): void
    {
        self::$databases[$database] = $dbinfo;
    }

    /** Só do stub: esvazia a pilha (desfazendo o que estiver aberto). */
    public static function reset(): void
    {
        while (self::$conn !== []) {
            self::rollback();
        }
    }

    public static function open($database, $dbinfo = null): PDO
    {
        $dbinfo ??= self::$databases[$database] ?? throw new \RuntimeException("Banco {$database} não registrado no stub.");
        $conn = new PDO('sqlite:' . $dbinfo['name']);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->beginTransaction();
        self::$conn[] = $conn;
        self::$dbinfo[] = $dbinfo;

        return $conn;
    }

    public static function get(): ?PDO
    {
        return self::$conn === [] ? null : self::$conn[count(self::$conn) - 1];
    }

    public static function getDatabaseInfo(): ?array
    {
        return self::$dbinfo === [] ? null : self::$dbinfo[count(self::$dbinfo) - 1];
    }

    public static function rollback(): ?bool
    {
        if (self::$conn === []) {
            return null;
        }
        array_pop(self::$conn)->rollBack();
        array_pop(self::$dbinfo);

        return true;
    }

    public static function close(): ?bool
    {
        if (self::$conn === []) {
            return null;
        }
        array_pop(self::$conn)->commit();
        array_pop(self::$dbinfo);

        return true;
    }
}
