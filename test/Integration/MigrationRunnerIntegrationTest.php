<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Integration;

use PhpDb\Migration\MigrationInterface;
use PhpDb\Migration\MigrationRunner;
use RuntimeException;

class MigrationRunnerIntegrationTest extends AbstractIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTableIfExists('migrations');
    }

    protected function tearDown(): void
    {
        $this->dropTableIfExists('migrations');
    }

    public function testCreatesMigrationsTable(): void
    {
        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $inspector = $runner->getInspector();
        $inspector->clearCache();

        self::assertTrue($inspector->tableExists('migrations'));
    }

    public function testIdempotentMigrationsTableCreation(): void
    {
        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();
        $runner->ensureMigrationsTable();

        $inspector = $runner->getInspector();
        $inspector->clearCache();

        self::assertTrue($inspector->tableExists('migrations'));
    }

    public function testRunsTestMigration(): void
    {
        $this->dropTableIfExists('integration_test');

        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $migration = new class extends AbstractMigration {
            public function getVersion(): string
            {
                return '20260101000000';
            }

            public function getDescription(): string
            {
                return 'Create integration test table';
            }

            protected function define(): void
            {
                $this->ensureTable('integration_test', function (CreateTable $table): void {
                    $id = new Column\Integer('id');
                    $id->setOption('unsigned', true);
                    $id->setOption('auto_increment', true);
                    $table->addColumn($id);
                    $table->addColumn(new Column\Varchar('name', 100));
                    $table->addConstraint(new Constraint\PrimaryKey(['id']));
                });
            }
        };

        $result = $runner->runMigration($migration);

        self::assertTrue($result['result']->isSuccess());

        $inspector = $runner->getInspector();
        $inspector->clearCache();

        self::assertTrue($inspector->tableExists('integration_test'));

        $this->dropTableIfExists('integration_test');
    }

    public function testRecordsMigrationInTrackingTable(): void
    {
        $this->dropTableIfExists('integration_test');

        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $migration = new class extends AbstractMigration {
            public function getVersion(): string
            {
                return '20260102000000';
            }

            public function getDescription(): string
            {
                return 'Tracking test migration';
            }

            protected function define(): void
            {
                $this->ensureTable('integration_test', function (CreateTable $table): void {
                    $table->addColumn(new Column\Integer('id'));
                    $table->addConstraint(new Constraint\PrimaryKey(['id']));
                });
            }
        };

        $runner->runMigration($migration);

        $versions = $runner->getAppliedVersions();
        self::assertContains('20260102000000', $versions);

        $this->dropTableIfExists('integration_test');
    }

    public function testTransactionCommitsOnSuccess(): void
    {
        $this->dropTableIfExists('tx_test');

        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $migration = new class extends AbstractMigration {
            public function getVersion(): string
            {
                return '20260103000000';
            }

            public function getDescription(): string
            {
                return 'Transaction commit test';
            }

            protected function define(): void
            {
                $this->ensureTable('tx_test', function (CreateTable $table): void {
                    $table->addColumn(new Column\Integer('id'));
                    $table->addConstraint(new Constraint\PrimaryKey(['id']));
                });
            }
        };

        $result = $runner->runMigration($migration);

        self::assertTrue($result['result']->isSuccess());
        self::assertContains('20260103000000', $runner->getAppliedVersions());

        $this->dropTableIfExists('tx_test');
    }

    public function testTransactionRollsBackOnException(): void
    {
        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn('20260104000000');
        $migration->method('getDescription')->willReturn('Exception test');
        $migration->method('up')->willThrowException(new RuntimeException('Simulated error'));

        $result = $runner->runMigration($migration);

        self::assertTrue($result['result']->isFailed());
        self::assertNotContains('20260104000000', $runner->getAppliedVersions());
    }

    private function createRunner(): MigrationRunner
    {
        return new MigrationRunner(
            adapter: $this->adapter,
            migrationsPath: __DIR__ . '/../Asset',
            migrationsNamespace: 'PhpDbTest\\Migration\\Asset',
        );
    }
}
