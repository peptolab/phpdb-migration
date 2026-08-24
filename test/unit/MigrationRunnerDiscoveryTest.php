<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\DriverInterface;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Adapter\Driver\StatementInterface;
use PhpDb\Adapter\Platform\PlatformInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Migration\SchemaInspector;
use PhpDb\Mysql\Sql\Platform as MysqlPlatform;
use PhpDb\ResultSet\ResultSetInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function file_put_contents;
use function glob;
use function implode;
use function is_dir;
use function mkdir;
use function rmdir;
use function sha1;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class MigrationRunnerDiscoveryTest extends TestCase
{
    private AdapterInterface&MockObject $adapter;
    private MetadataInterface&MockObject $metadata;
    private string $fixturesPath;
    private string $namespace = 'PhpDbTest\\Migration\\Generated';

    #[Test]
    public function discoverMigrationsIgnoresClassesNotImplementingMigrationInterface(): void
    {
        $this->writeFixtureDir();
        $version   = '20260201000001';
        $className = "Version{$version}NotAMigration";
        file_put_contents(
            "{$this->fixturesPath}/{$className}.php",
            "<?php\ndeclare(strict_types=1);\nnamespace {$this->namespace};\nclass {$className} {}\n",
        );

        $runner = $this->createRunner($this->fixturesPath);

        static::assertSame([], $runner->discoverMigrations());
    }

    #[Test]
    public function discoverMigrationsIgnoresNonVersionPrefixedFiles(): void
    {
        $this->writeFixtureDir();
        file_put_contents("{$this->fixturesPath}/Helper.php", "<?php\n// not a migration\n");

        $runner = $this->createRunner($this->fixturesPath);

        static::assertSame([], $runner->discoverMigrations());
    }

    #[Test]
    public function discoverMigrationsReturnsEmptyArrayWhenDirectoryMissing(): void
    {
        $runner = $this->createRunner($this->fixturesPath);

        static::assertSame([], $runner->discoverMigrations());
    }

    #[Test]
    public function discoverMigrationsSortsByVersionAndCachesResult(): void
    {
        $this->writeFixtureDir();
        $this->writeMigrationFixture('20260201000002', 'Second migration');
        $this->writeMigrationFixture('20260201000001', 'First migration');

        $runner = $this->createRunner($this->fixturesPath);

        $migrations = $runner->discoverMigrations();
        static::assertCount(2, $migrations);
        static::assertSame('20260201000001', $migrations[0]->getVersion());
        static::assertSame('20260201000002', $migrations[1]->getVersion());

        // Second call must return the cached array, not re-scan the directory.
        static::assertSame($migrations, $runner->discoverMigrations());
    }

    #[Test]
    public function getInspectorReturnsSchemaInspector(): void
    {
        $runner = $this->createRunner($this->fixturesPath);

        static::assertInstanceOf(SchemaInspector::class, $runner->getInspector());
    }

    #[Test]
    public function getPendingMigrationsFiltersOutApplied(): void
    {
        $this->writeFixtureDir();
        $this->writeMigrationFixture('20260201000003', 'Applied migration');
        $this->writeMigrationFixture('20260201000004', 'Pending migration');

        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->stubSelect([['version' => '20260201000003']]);

        $runner  = $this->createRunner($this->fixturesPath);
        $pending = $runner->getPendingMigrations();

        $versions = array_map(static fn($m) => $m->getVersion(), array_values($pending));
        static::assertSame(['20260201000004'], $versions);
    }

    #[Test]
    public function getStatusReportsAppliedAndPendingMigrations(): void
    {
        $this->writeFixtureDir();
        $this->writeMigrationFixture('20260201000005', 'Applied migration');
        $this->writeMigrationFixture('20260201000006', 'Pending migration');

        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->stubSelect([
            [
                'version'     => '20260201000005',
                'description' => 'Applied migration',
                'executed_at' => '2026-02-01 00:00:00',
            ],
        ]);

        $runner = $this->createRunner($this->fixturesPath);
        $status = $runner->getStatus();

        static::assertCount(2, $status);
        static::assertSame('applied', $status[0]['status']);
        static::assertSame('2026-02-01 00:00:00', $status[0]['executed_at']);
        static::assertSame('pending', $status[1]['status']);
        static::assertNull($status[1]['executed_at']);
    }

    #[Test]
    public function previewPendingReturnsSqlForPendingMigrations(): void
    {
        $this->writeFixtureDir();
        $this->writeMigrationFixture('20260201000007', 'Preview migration');

        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->stubSelect([]);

        $runner   = $this->createRunner($this->fixturesPath);
        $previews = $runner->previewPending();

        static::assertCount(1, $previews);
        static::assertSame('20260201000007', $previews[0]['version']);
        static::assertSame([], $previews[0]['sql']);
    }

    #[Test]
    public function runPendingRunsEveryPendingMigration(): void
    {
        $this->writeFixtureDir();
        $this->writeMigrationFixture('20260201000008', 'First');
        $this->writeMigrationFixture('20260201000009', 'Second');

        $this->metadata->method('getTableNames')->willReturn(['migrations']);
        $this->stubSelect([]);

        $runner  = $this->createRunner($this->fixturesPath);
        $results = $runner->runPending();

        static::assertCount(2, $results);
        static::assertTrue($results[0]['result']->isSuccess());
        static::assertTrue($results[1]['result']->isSuccess());
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

        $this->fixturesPath = sys_get_temp_dir() . '/phpdb-migration-discovery-' . sha1((string) __CLASS__);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->fixturesPath)) {
            return;
        }

        foreach (glob("{$this->fixturesPath}/*.php") ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->fixturesPath);
    }

    private function createRunner(string $path): MigrationRunner
    {
        return new MigrationRunner(
            adapter: $this->adapter,
            migrationsPath: $path,
            migrationsNamespace: $this->namespace,
            metadata: $this->metadata,
        );
    }

    /** @param array<array{version: string}> $rows */
    private function stubSelect(array $rows): void
    {
        $this->adapter->method('prepareQuery')->willReturn($this->createMock(StatementInterface::class));

        $resultSet = $this->createMock(ResultSetInterface::class);
        $resultSet->method('toArray')->willReturn($rows);

        $result = $this->createMock(ResultInterface::class);
        $result->method('getQueryResult')->willReturn($resultSet);

        $this->adapter->method('executeQuery')->willReturn($result);
    }

    private function writeFixtureDir(): void
    {
        if (! is_dir($this->fixturesPath)) {
            mkdir($this->fixturesPath, 0o755, true);
        }
    }

    private function writeMigrationFixture(string $version, string $description): void
    {
        $className = 'Version' . $version . 'F' . uniqid();
        $content   = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            use PhpDb\\Migration\\AbstractMigration;

            class {$className} extends AbstractMigration
            {
                public function getVersion(): string
                {
                    return '{$version}';
                }

                public function getDescription(): string
                {
                    return '{$description}';
                }

                protected function define(): void
                {
                }
            }

            PHP;

        file_put_contents("{$this->fixturesPath}/{$className}.php", $content);
    }
}
