<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Integration;

use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Sql\Ddl\DropTable;
use PhpDb\Sql\Sql;
use PHPUnit\Framework\TestCase;
use Throwable;

use function getenv;

abstract class AbstractIntegrationTestCase extends TestCase
{
    protected AdapterInterface $adapter;

    protected function setUp(): void
    {
        $driver = getenv('DB_DRIVER') ?: $_ENV['DB_DRIVER'] ?? '';
        $host   = getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? '';
        $name   = getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?? '';
        $user   = getenv('DB_USER') ?: $_ENV['DB_USER'] ?? '';
        $pass   = getenv('DB_PASS') ?: $_ENV['DB_PASS'] ?? '';
        $port   = getenv('DB_PORT') ?: $_ENV['DB_PORT'] ?? '3306';

        if ($driver === '' || $host === '' || $name === '') {
            self::markTestSkipped('Database not configured (set DB_DRIVER, DB_HOST, DB_NAME env vars)');
        }

        try {
            $this->adapter = new Adapter([
                'driver'   => $driver,
                'hostname' => $host,
                'database' => $name,
                'username' => $user,
                'password' => $pass,
                'port'     => (int) $port,
            ]);

            $this->adapter->getDriver()->getConnection()->connect();
        } catch (Throwable $e) {
            self::markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    protected function dropTableIfExists(string $tableName): void
    {
        if (! isset($this->adapter)) {
            return;
        }

        $drop = new DropTable($tableName);
        $drop->ifExists();

        $sql       = new Sql($this->adapter);
        $sqlString = $sql->buildSqlString($drop);

        $this->adapter->query($sqlString, []);
    }
}
