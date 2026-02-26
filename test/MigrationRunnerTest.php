<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\ConnectionInterface;
use PhpDb\Adapter\Driver\DriverInterface;
use PhpDb\Adapter\Platform\PlatformInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Migration\MigrationInterface;
use PhpDb\Migration\MigrationResult;
use PhpDb\Migration\MigrationRunner;
use PhpDb\ResultSet\ResultSetInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function implode;

class MigrationRunnerTest extends TestCase
{
    private AdapterInterface&MockObject $adapter;
    private MetadataInterface&MockObject $metadata;
    private DriverInterface&MockObject $driver;
    private ConnectionInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->adapter    = $this->createMock(AdapterInterface::class);
        $this->metadata   = $this->createMock(MetadataInterface::class);
        $this->driver     = $this->createMock(DriverInterface::class);
        $this->connection = $this->createMock(ConnectionInterface::class);

        $platform = $this->createMock(PlatformInterface::class);
        $this->adapter->method('getPlatform')->willReturn($platform);
        $this->adapter->method('getDriver')->willReturn($this->driver);
        $this->driver->method('getConnection')->willReturn($this->connection);

        $platform->method('quoteIdentifier')
            ->willReturnCallback(fn (string $id): string => '`' . $id . '`');
        $platform->method('quoteIdentifierChain')
            ->willReturnCallback(fn (array $ids): string => '`' . implode('`.`', $ids) . '`');
    }

    public function testEnsureMigrationsTableUsesIfNotExists(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        $capturedSql = null;
        $resultSet   = $this->createResultSet();
        $this->adapter->method('query')
            ->willReturnCallback(function (string $sql) use (&$capturedSql, $resultSet) {
                $capturedSql = $sql;
                return $resultSet;
            });

        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        self::assertNotNull($capturedSql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $capturedSql);
    }

    public function testRunMigrationCommitsOnSuccess(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createSuccessfulMigration('20260201000000', 'Test migration');

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::once())->method('commit');
        $this->connection->expects(self::never())->method('rollback');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        self::assertSame('20260201000000', $result['version']);
        self::assertTrue($result['result']->isSuccess());
    }

    public function testRunMigrationRollsBackOnFailure(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createFailedMigration('20260201000000', 'Failing migration');

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::never())->method('commit');
        $this->connection->expects(self::once())->method('rollback');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        self::assertTrue($result['result']->isFailed());
    }

    public function testRunMigrationRollsBackOnException(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn('20260201000000');
        $migration->method('getDescription')->willReturn('Exception migration');
        $migration->method('up')->willThrowException(new RuntimeException('DB error'));

        $this->connection->expects(self::once())->method('beginTransaction');
        $this->connection->expects(self::never())->method('commit');
        $this->connection->expects(self::once())->method('rollback');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        self::assertTrue($result['result']->isFailed());
        self::assertSame('DB error', $result['result']->errorMessage);
    }

    public function testSkippedMigrationStartsNoTransaction(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);

        $resultSet = $this->createResultSet([
            ['version' => '20260201000000'],
        ]);
        $this->adapter->method('query')->willReturn($resultSet);

        $migration = $this->createSuccessfulMigration('20260201000000', 'Already applied');

        $this->connection->expects(self::never())->method('beginTransaction');
        $this->connection->expects(self::never())->method('commit');
        $this->connection->expects(self::never())->method('rollback');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        self::assertTrue($result['result']->isSkipped());
    }

    public function testMigrationRecordingHappensInsideTransaction(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createSuccessfulMigration('20260201000000', 'Test migration');

        $callOrder = [];
        $this->connection->method('beginTransaction')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'beginTransaction';
                return $this->connection;
            });
        $this->connection->method('commit')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'commit';
                return $this->connection;
            });

        $runner = $this->createRunner();
        $runner->runMigration($migration);

        self::assertSame('beginTransaction', $callOrder[0]);
        self::assertSame('commit', $callOrder[1]);
    }

    private function createRunner(): MigrationRunner
    {
        return new MigrationRunner(
            adapter: $this->adapter,
            migrationsPath: __DIR__ . '/Asset',
            migrationsNamespace: 'PhpDbTest\\Migration\\Asset',
            metadata: $this->metadata,
        );
    }

    private function createResultSet(array $rows = []): ResultSetInterface&MockObject
    {
        $resultSet = $this->createMock(ResultSetInterface::class);
        $resultSet->method('toArray')->willReturn($rows);

        return $resultSet;
    }

    private function setupEmptyAppliedVersions(): void
    {
        $this->adapter->method('query')->willReturn($this->createResultSet());
    }

    private function createSuccessfulMigration(string $version, string $description): MigrationInterface&MockObject
    {
        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn($version);
        $migration->method('getDescription')->willReturn($description);
        $migration->method('up')->willReturn(MigrationResult::success(['executed']));

        return $migration;
    }

    private function createFailedMigration(string $version, string $description): MigrationInterface&MockObject
    {
        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn($version);
        $migration->method('getDescription')->willReturn($description);
        $migration->method('up')->willReturn(MigrationResult::failed('Migration failed'));

        return $migration;
    }
}
