<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Integration;

use PhpDb\Migration\AbstractMigration;
use PhpDb\Migration\SchemaInspector;
use PhpDb\Sql\Ddl\Column;

class AbstractMigrationIntegrationTest extends AbstractIntegrationTestCase
{
    private SchemaInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        if (! isset($this->adapter)) {
            return;
        }

        $this->inspector = new SchemaInspector($this->adapter);

        $this->dropTableIfExists('child_table');
        $this->dropTableIfExists('parent_table');

        $this->adapter->query(
            'CREATE TABLE `parent_table` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`name` VARCHAR(100) NOT NULL, '
            . '`email` VARCHAR(255) NOT NULL, '
            . 'PRIMARY KEY (`id`)'
            . ')',
            [],
        );

        $this->adapter->query(
            'CREATE TABLE `child_table` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`parent_id` INT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (`id`)'
            . ')',
            [],
        );

        $this->inspector->clearCache();
    }

    protected function tearDown(): void
    {
        $this->dropTableIfExists('child_table');
        $this->dropTableIfExists('parent_table');
    }

    public function testEnsureIndexCreatesRealIndex(): void
    {
        $migration = new class extends AbstractMigration {
            public function getVersion(): string
            {
                return '20260201000000';
            }

            public function getDescription(): string
            {
                return 'Create index test';
            }

            protected function define(): void
            {
                $this->ensureIndex('parent_table', 'idx_parent_email', ['email']);
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        self::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        self::assertTrue($this->inspector->indexExists('parent_table', 'idx_parent_email'));
    }

    public function testEnsureForeignKeyCreatesRealFK(): void
    {
        $migration = new class extends AbstractMigration {
            public function getVersion(): string
            {
                return '20260202000000';
            }

            public function getDescription(): string
            {
                return 'Create FK test';
            }

            protected function define(): void
            {
                $this->ensureForeignKey(
                    'child_table',
                    'fk_child_parent',
                    'parent_id',
                    'parent_table',
                    'id',
                    'CASCADE',
                    'CASCADE',
                );
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        self::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        self::assertTrue($this->inspector->constraintExists('child_table', 'fk_child_parent'));
    }

    public function testDropIndexIfExistsRemovesRealIndex(): void
    {
        $this->adapter->query('CREATE INDEX `idx_parent_name` ON `parent_table` (`name`)', []);
        $this->inspector->clearCache();

        self::assertTrue($this->inspector->indexExists('parent_table', 'idx_parent_name'));

        $migration = new class extends AbstractMigration {
            public function getVersion(): string
            {
                return '20260203000000';
            }

            public function getDescription(): string
            {
                return 'Drop index test';
            }

            protected function define(): void
            {
                $this->dropIndexIfExists('parent_table', 'idx_parent_name');
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        self::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        self::assertFalse($this->inspector->indexExists('parent_table', 'idx_parent_name'));
    }

    public function testDropForeignKeyIfExistsRemovesRealFK(): void
    {
        $this->adapter->query(
            'ALTER TABLE `child_table` ADD CONSTRAINT `fk_child_parent_drop` '
            . 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
            [],
        );
        $this->inspector->clearCache();

        self::assertTrue($this->inspector->constraintExists('child_table', 'fk_child_parent_drop'));

        $migration = new class extends AbstractMigration {
            public function getVersion(): string
            {
                return '20260204000000';
            }

            public function getDescription(): string
            {
                return 'Drop FK test';
            }

            protected function define(): void
            {
                $this->dropForeignKeyIfExists('child_table', 'fk_child_parent_drop');
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        self::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        self::assertFalse($this->inspector->constraintExists('child_table', 'fk_child_parent_drop'));
    }

    public function testModifyColumnAltersRealColumn(): void
    {
        $migration = new class extends AbstractMigration {
            public function getVersion(): string
            {
                return '20260205000000';
            }

            public function getDescription(): string
            {
                return 'Modify column test';
            }

            protected function define(): void
            {
                $this->modifyColumn('parent_table', 'name', new Column\Varchar('name', 200));
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        self::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        $columnDetails = $this->inspector->getColumn('parent_table', 'name');
        self::assertNotNull($columnDetails);
        self::assertSame(200, $columnDetails['maxLength']);
    }
}
