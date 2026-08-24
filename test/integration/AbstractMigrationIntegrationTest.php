<?php

declare(strict_types=1);

namespace PhpDbIntegrationTest\Migration;

use PhpDb\Migration\AbstractMigration;
use PhpDb\Migration\SchemaInspector;
use PhpDb\Sql\Ddl\Column;
use PHPUnit\Framework\Attributes\Test;

class AbstractMigrationIntegrationTest extends AbstractIntegrationTestCase
{
    private SchemaInspector $inspector;

    #[Test]
    public function dropForeignKeyIfExistsRemovesRealFK(): void
    {
        $this->execute(
            'ALTER TABLE `child_table` ADD CONSTRAINT `fk_child_parent_drop` '
                . 'FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`)',
        );
        $this->inspector->clearCache();

        static::assertTrue($this->inspector->constraintExists('child_table', 'fk_child_parent_drop'));

        $migration = new class extends AbstractMigration {
            public function getDescription(): string
            {
                return 'Drop FK test';
            }

            public function getVersion(): string
            {
                return '20260204000000';
            }

            protected function define(): void
            {
                $this->dropForeignKeyIfExists('child_table', 'fk_child_parent_drop');
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        static::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        static::assertFalse($this->inspector->constraintExists('child_table', 'fk_child_parent_drop'));
    }

    #[Test]
    public function dropIndexIfExistsRemovesRealIndex(): void
    {
        $this->execute('CREATE INDEX `idx_parent_name` ON `parent_table` (`name`)');
        $this->inspector->clearCache();

        static::assertTrue($this->inspector->indexExists('parent_table', 'idx_parent_name'));

        $migration = new class extends AbstractMigration {
            public function getDescription(): string
            {
                return 'Drop index test';
            }

            public function getVersion(): string
            {
                return '20260203000000';
            }

            protected function define(): void
            {
                $this->dropIndexIfExists('parent_table', 'idx_parent_name');
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        static::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        static::assertFalse($this->inspector->indexExists('parent_table', 'idx_parent_name'));
    }

    #[Test]
    public function ensureCheckConstraintCreatesRealConstraint(): void
    {
        $migration = new class extends AbstractMigration {
            public function getDescription(): string
            {
                return 'Create check constraint test';
            }

            public function getVersion(): string
            {
                return '20260206000000';
            }

            protected function define(): void
            {
                $this->ensureCheckConstraint('parent_table', 'chk_parent_name', "`name` <> ''");
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        static::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        static::assertTrue($this->inspector->constraintExists('parent_table', 'chk_parent_name'));
    }

    #[Test]
    public function ensureForeignKeyCreatesRealFK(): void
    {
        $migration = new class extends AbstractMigration {
            public function getDescription(): string
            {
                return 'Create FK test';
            }

            public function getVersion(): string
            {
                return '20260202000000';
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
        static::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        static::assertTrue($this->inspector->constraintExists('child_table', 'fk_child_parent'));
    }

    #[Test]
    public function ensureIndexCreatesRealIndex(): void
    {
        $migration = new class extends AbstractMigration {
            public function getDescription(): string
            {
                return 'Create index test';
            }

            public function getVersion(): string
            {
                return '20260201000000';
            }

            protected function define(): void
            {
                $this->ensureIndex('parent_table', 'idx_parent_email', ['email']);
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        static::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        static::assertTrue($this->inspector->indexExists('parent_table', 'idx_parent_email'));
    }

    #[Test]
    public function modifyColumnAltersRealColumn(): void
    {
        $migration = new class extends AbstractMigration {
            public function getDescription(): string
            {
                return 'Modify column test';
            }

            public function getVersion(): string
            {
                return '20260205000000';
            }

            protected function define(): void
            {
                $this->modifyColumn('parent_table', 'name', new Column\Varchar('name', 200));
            }
        };

        $result = $migration->up($this->adapter, $this->inspector);
        static::assertTrue($result->isSuccess());

        $this->inspector->clearCache();
        $columnDetails = $this->inspector->getColumn('parent_table', 'name');
        static::assertNotNull($columnDetails);
        static::assertSame(200, $columnDetails['maxLength']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! isset($this->adapter)) {
            return;
        }

        $this->inspector = new SchemaInspector($this->adapter);

        $this->dropTableIfExists('child_table');
        $this->dropTableIfExists('parent_table');

        $this->execute(
            'CREATE TABLE `parent_table` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`name` VARCHAR(100) NOT NULL, '
                . '`email` VARCHAR(255) NOT NULL, '
                . 'PRIMARY KEY (`id`)'
                . ')',
        );

        $this->execute(
            'CREATE TABLE `child_table` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . '`parent_id` INT UNSIGNED NOT NULL, '
                . 'PRIMARY KEY (`id`)'
                . ')',
        );

        $this->inspector->clearCache();
    }

    protected function tearDown(): void
    {
        $this->dropTableIfExists('child_table');
        $this->dropTableIfExists('parent_table');
    }

    private function execute(string $sql): void
    {
        $this->adapter->executeQuery($this->adapter->prepareQuery($sql));
    }
}
