<?php

declare(strict_types=1);

namespace PhpDbIntegrationTest\Migration;

use PhpDb\Migration\AbstractMigration;
use PhpDb\Migration\MigrationInterface;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Sql\Ddl\Column;
use PhpDb\Sql\Ddl\Constraint;
use PhpDb\Sql\Ddl\CreateTable;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class MigrationRunnerIntegrationTest extends AbstractIntegrationTestCase
{
    #[Test]
    public function createsMigrationsTable(): void
    {
        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $inspector = $runner->getInspector();
        $inspector->clearCache();

        static::assertTrue($inspector->tableExists('migrations'));
    }

    #[Test]
    public function failedMigrationIsNotRecorded(): void
    {
        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn('20260104000000');
        $migration->method('getDescription')->willReturn('Exception test');
        $migration->method('up')->willThrowException(new RuntimeException('Simulated error'));

        $result = $runner->runMigration($migration);

        static::assertTrue($result['result']->isFailed());
        static::assertNotContains('20260104000000', $runner->getAppliedVersions());
    }

    #[Test]
    public function idempotentMigrationsTableCreation(): void
    {
        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();
        $runner->ensureMigrationsTable();

        $inspector = $runner->getInspector();
        $inspector->clearCache();

        static::assertTrue($inspector->tableExists('migrations'));
    }

    #[Test]
    public function recordsMigrationInTrackingTable(): void
    {
        $this->dropTableIfExists('integration_test');

        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $migration = new class extends AbstractMigration {
            public function getDescription(): string
            {
                return 'Tracking test migration';
            }

            public function getVersion(): string
            {
                return '20260102000000';
            }

            protected function define(): void
            {
                $this->ensureTable('integration_test', static function (CreateTable $table): void {
                    $table->addColumn(new Column\Integer('id'));
                    $table->addConstraint(new Constraint\PrimaryKey(['id']));
                });
            }
        };

        $runner->runMigration($migration);

        $versions = $runner->getAppliedVersions();
        static::assertContains('20260102000000', $versions);

        $this->dropTableIfExists('integration_test');
    }

    #[Test]
    public function runsTestMigration(): void
    {
        $this->dropTableIfExists('integration_test');

        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $migration = new class extends AbstractMigration {
            public function getDescription(): string
            {
                return 'Create integration test table';
            }

            public function getVersion(): string
            {
                return '20260101000000';
            }

            protected function define(): void
            {
                $this->ensureTable('integration_test', static function (CreateTable $table): void {
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

        static::assertTrue($result['result']->isSuccess());

        $inspector = $runner->getInspector();
        $inspector->clearCache();

        static::assertTrue($inspector->tableExists('integration_test'));

        $this->dropTableIfExists('integration_test');
    }

    #[Test]
    public function successfulMigrationIsRecordedAndTableCreated(): void
    {
        $this->dropTableIfExists('tx_test');

        $runner = $this->createRunner();
        $runner->ensureMigrationsTable();

        $migration = new class extends AbstractMigration {
            public function getDescription(): string
            {
                return 'Success recording test';
            }

            public function getVersion(): string
            {
                return '20260103000000';
            }

            protected function define(): void
            {
                $this->ensureTable('tx_test', static function (CreateTable $table): void {
                    $table->addColumn(new Column\Integer('id'));
                    $table->addConstraint(new Constraint\PrimaryKey(['id']));
                });
            }
        };

        $result = $runner->runMigration($migration);

        static::assertTrue($result['result']->isSuccess());
        static::assertContains('20260103000000', $runner->getAppliedVersions());

        $this->dropTableIfExists('tx_test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTableIfExists('migrations');
    }

    protected function tearDown(): void
    {
        $this->dropTableIfExists('migrations');
    }

    private function createRunner(): MigrationRunner
    {
        return new MigrationRunner(
            adapter: $this->adapter,
            migrationsPath: __DIR__ . '/../asset',
            migrationsNamespace: 'PhpDbTest\\Migration\\Asset',
        );
    }
}
