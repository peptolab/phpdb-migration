<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Integration;

use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\Pdo\Result;
use PhpDb\Adapter\Driver\Pdo\Statement;
use PhpDb\Mysql\AdapterPlatform;
use PhpDb\Mysql\Pdo\Connection;
use PhpDb\Mysql\Pdo\Driver;
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
        $host = getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? '';
        $name = getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?? '';
        $user = getenv('DB_USER') ?: $_ENV['DB_USER'] ?? '';
        $pass = getenv('DB_PASS') ?: $_ENV['DB_PASS'] ?? '';
        $port = getenv('DB_PORT') ?: $_ENV['DB_PORT'] ?? '3306';

        if ($host === '' || $name === '') {
            self::markTestSkipped('Database not configured (set DB_HOST, DB_NAME env vars)');
        }

        try {
            $connection = new Connection([
                'hostname' => $host,
                'database' => $name,
                'username' => $user,
                'password' => $pass,
                'port'     => (int) $port,
            ]);

            $driver   = new Driver($connection, new Statement(), new Result());
            $platform = new AdapterPlatform($driver);

            $this->adapter = new Adapter($driver, $platform);
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
