<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\DriverInterface;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Adapter\Driver\StatementInterface;
use PhpDb\Adapter\Platform\PlatformInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Migration\MigrationInterface;
use PhpDb\Migration\MigrationResult;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Mysql\Sql\Platform as MysqlPlatform;
use PhpDb\ResultSet\ResultSetInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function implode;

class MigrationRunnerTest extends TestCase
{
    private AdapterInterface&MockObject $adapter;
    private MetadataInterface&MockObject $metadata;

    #[Test]
    public function ensureMigrationsTableUsesIfNotExists(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        $capturedSql = null;
        $this->adapter
            ->method('prepareQuery')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return $this->createMock(StatementInterface::class);
            });
        $this->adapter->method('executeQuery')->willReturn($this->createResult());

        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        static::assertNotNull($capturedSql);
        static::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $capturedSql);
    }

    #[Test]
    public function runMigrationReturnsFailedOnException(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn('20260201000000');
        $migration->method('getDescription')->willReturn('Exception migration');
        $migration->method('up')->willThrowException(new RuntimeException('DB error'));

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        static::assertTrue($result['result']->isFailed());
        static::assertSame('DB error', $result['result']->errorMessage);
    }

    #[Test]
    public function runMigrationReturnsFailedResult(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createFailedMigration('20260201000000', 'Failing migration');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        static::assertTrue($result['result']->isFailed());
    }

    #[Test]
    public function runMigrationReturnsSuccessResult(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createSuccessfulMigration('20260201000000', 'Test migration');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        static::assertSame('20260201000000', $result['version']);
        static::assertTrue($result['result']->isSuccess());
    }

    #[Test]
    public function skippedMigrationIsNotReRun(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);

        $this->adapter->method('prepareQuery')->willReturn($this->createMock(StatementInterface::class));
        $this->adapter
            ->method('executeQuery')
            ->willReturn($this->createResult([
                ['version' => '20260201000000'],
            ]));

        $migration = $this->createSuccessfulMigration('20260201000000', 'Already applied');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        static::assertTrue($result['result']->isSkipped());
    }

    protected function setUp(): void
    {
        $this->adapter  = $this->createMock(AdapterInterface::class);
        $this->metadata = $this->createMock(MetadataInterface::class);

        $platform             = $this->createMock(PlatformInterface::class);
        $sqlPlatformDecorator = new MysqlPlatform();
        $driver               = $this->createMock(DriverInterface::class);

        $this->adapter->method('getPlatform')->willReturn($platform);
        $this->adapter->method('getDriver')->willReturn($driver);
        $platform->method('getSqlPlatformDecorator')->willReturn($sqlPlatformDecorator);
        $platform->method('quoteIdentifier')
            ->willReturnCallback(static fn(string $id): string => "`{$id}`");
        $platform->method('quoteIdentifierChain')
            ->willReturnCallback(static fn(array $ids): string => '`' . implode('`.`', $ids) . '`');
    }

    private function createFailedMigration(string $version, string $description): MigrationInterface&MockObject
    {
        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn($version);
        $migration->method('getDescription')->willReturn($description);
        $migration->method('up')->willReturn(MigrationResult::failed('Migration failed'));

        return $migration;
    }

    /** @param array<array<string, mixed>> $rows */
    private function createResult(array $rows = []): ResultInterface&MockObject
    {
        $result = $this->createMock(ResultInterface::class);
        $result->method('getQueryResult')->willReturn($this->createResultSet($rows));

        return $result;
    }

    private function createResultSet(array $rows = []): ResultSetInterface&MockObject
    {
        $resultSet = $this->createMock(ResultSetInterface::class);
        $resultSet->method('toArray')->willReturn($rows);

        return $resultSet;
    }

    private function createRunner(): MigrationRunner
    {
        return new MigrationRunner(
            adapter: $this->adapter,
            migrationsPath: __DIR__ . '/../asset',
            migrationsNamespace: 'PhpDbTest\\Migration\\Asset',
            metadata: $this->metadata,
        );
    }

    private function createSuccessfulMigration(string $version, string $description): MigrationInterface&MockObject
    {
        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn($version);
        $migration->method('getDescription')->willReturn($description);
        $migration->method('up')->willReturn(MigrationResult::success(['executed']));

        return $migration;
    }

    private function setupEmptyAppliedVersions(): void
    {
        $this->adapter->method('prepareQuery')->willReturn($this->createMock(StatementInterface::class));
        $this->adapter->method('executeQuery')->willReturn($this->createResult());
    }
}
