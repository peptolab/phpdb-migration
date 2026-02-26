<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\DriverInterface;
use PhpDb\Adapter\Platform\PlatformInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Migration\MigrationInterface;
use PhpDb\Migration\MigrationResult;
use PhpDb\Migration\MigrationRunner;
use PhpDb\ResultSet\ResultSetInterface;
use PhpDb\Sql\Platform\AbstractPlatform;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function implode;

class MigrationRunnerTest extends TestCase
{
    private AdapterInterface&MockObject $adapter;
    private MetadataInterface&MockObject $metadata;

    protected function setUp(): void
    {
        $this->adapter  = $this->createMock(AdapterInterface::class);
        $this->metadata = $this->createMock(MetadataInterface::class);

        $platform             = $this->createMock(PlatformInterface::class);
        $sqlPlatformDecorator = $this->createMock(AbstractPlatform::class);
        $driver               = $this->createMock(DriverInterface::class);

        $this->adapter->method('getPlatform')->willReturn($platform);
        $this->adapter->method('getDriver')->willReturn($driver);

        $sqlPlatformDecorator->method('getDecorators')->willReturn([]);
        $platform->method('getSqlPlatformDecorator')->willReturn($sqlPlatformDecorator);
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

    public function testRunMigrationReturnsSuccessResult(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createSuccessfulMigration('20260201000000', 'Test migration');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        self::assertSame('20260201000000', $result['version']);
        self::assertTrue($result['result']->isSuccess());
    }

    public function testRunMigrationReturnsFailedResult(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createFailedMigration('20260201000000', 'Failing migration');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        self::assertTrue($result['result']->isFailed());
    }

    public function testRunMigrationReturnsFailedOnException(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->setupEmptyAppliedVersions();

        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn('20260201000000');
        $migration->method('getDescription')->willReturn('Exception migration');
        $migration->method('up')->willThrowException(new RuntimeException('DB error'));

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        self::assertTrue($result['result']->isFailed());
        self::assertSame('DB error', $result['result']->errorMessage);
    }

    public function testSkippedMigrationIsNotReRun(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['migrations']);

        $resultSet = $this->createResultSet([
            ['version' => '20260201000000'],
        ]);
        $this->adapter->method('query')->willReturn($resultSet);

        $migration = $this->createSuccessfulMigration('20260201000000', 'Already applied');

        $runner = $this->createRunner();
        $result = $runner->runMigration($migration);

        self::assertTrue($result['result']->isSkipped());
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
