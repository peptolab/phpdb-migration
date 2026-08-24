<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Adapter\Driver\StatementInterface;
use PhpDb\Adapter\Platform\PlatformInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Metadata\Object\ColumnObject;
use PhpDb\Metadata\Object\ConstraintObject;
use PhpDb\Migration\AbstractMigration;
use PhpDb\Migration\MismatchStrategy;
use PhpDb\Migration\SchemaInspector;
use PhpDb\Mysql\Sql\Platform as MysqlPlatform;
use PhpDb\ResultSet\ResultSetInterface;
use PhpDb\Sql\Ddl\Column;
use PhpDb\Sql\Ddl\Constraint;
use PhpDb\Sql\Ddl\CreateTable;
use PhpDbTest\Migration\Asset\TestableMigration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function implode;
use function str_contains;

class AbstractMigrationTest extends TestCase
{
    /** @var array<string> */
    private array $executedQueries = [];

    #[Test]
    public function checkForeignKeyDefinitionWithAlterStrategy(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $constraint = new ConstraintObject('fk_posts_user', 'posts');
        $constraint->setType('FOREIGN KEY');
        $constraint->setColumns(['user_id']);
        $constraint->setReferencedTableName('users');
        $constraint->setReferencedColumns(['id']);
        $constraint->setDeleteRule('RESTRICT');
        $constraint->setUpdateRule('RESTRICT');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureForeignKey(
                'posts',
                'fk_posts_user',
                'user_id',
                'users',
                'id',
                'CASCADE',
                'RESTRICT',
            );
        });

        $migration->setMismatchStrategy(MismatchStrategy::Alter);
        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->hasMismatches());
        $dropQuery = $this->findQuery('DROP CONSTRAINT');
        static::assertNotNull($dropQuery);
        $addQuery = $this->findQuery('FOREIGN KEY');
        static::assertNotNull($addQuery);
    }

    #[Test]
    public function checkIndexDefinitionWithAlterStrategy(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $constraint = new ConstraintObject('idx_users_name', 'users');
        $constraint->setType('UNIQUE');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter, [
            ['Key_name' => 'idx_users_name', 'Column_name' => 'name'],
        ]);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureIndex('users', 'idx_users_name', ['name', 'email']);
        });

        $migration->setMismatchStrategy(MismatchStrategy::Alter);
        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->hasMismatches());
        $dropQuery = $this->findQuery('DROP INDEX');
        static::assertNotNull($dropQuery);
    }

    #[Test]
    public function dropColumnIfExistsDropsExistingColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $col = new ColumnObject('nickname', 'users');
        $col->setDataType('varchar');
        $metadata->method('getColumns')->willReturn([$col]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callDropColumnIfExists('users', 'nickname');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $dropQuery = $this->findQuery('DROP COLUMN');
        static::assertNotNull($dropQuery);
    }

    #[Test]
    public function dropColumnIfExistsSkipsMissingColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getColumns')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callDropColumnIfExists('users', 'nickname');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function dropColumnIfExistsSkipsMissingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callDropColumnIfExists('users', 'nickname');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
    }

    #[Test]
    public function dropForeignKeyIfExistsDropsExistingConstraint(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $constraint = new ConstraintObject('fk_posts_user', 'posts');
        $constraint->setType('FOREIGN KEY');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callDropForeignKeyIfExists('posts', 'fk_posts_user');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('DROP CONSTRAINT');
        static::assertNotNull($ddlQuery);
    }

    #[Test]
    public function dropForeignKeyIfExistsSkipsMissing(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callDropForeignKeyIfExists('posts', 'fk_nonexistent');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
    }

    #[Test]
    public function dropIndexIfExistsDropsExistingIndex(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $constraint = new ConstraintObject('idx_users_email', 'users');
        $constraint->setType('UNIQUE');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter, [
            ['Key_name' => 'idx_users_email', 'Column_name' => 'email'],
        ]);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callDropIndexIfExists('users', 'idx_users_email');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('DROP INDEX');
        static::assertNotNull($ddlQuery);
    }

    #[Test]
    public function dropIndexIfExistsSkipsMissing(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callDropIndexIfExists('users', 'idx_nonexistent');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
    }

    #[Test]
    public function dropTableIfExistsDropsExistingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callDropTableIfExists('users');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $dropQuery = $this->findQuery('DROP TABLE');
        static::assertNotNull($dropQuery);
    }

    #[Test]
    public function dropTableIfExistsSkipsMissing(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callDropTableIfExists('users');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function ensureCheckConstraintCreatesNewConstraint(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureCheckConstraint('posts', 'chk_posts_status', "status IN ('draft', 'published')");
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('CHECK');
        static::assertNotNull($ddlQuery);
        static::assertStringContainsString('chk_posts_status', $ddlQuery);
    }

    #[Test]
    public function ensureCheckConstraintSkipsExisting(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $constraint = new ConstraintObject('chk_posts_status', 'posts');
        $constraint->setType('CHECK');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureCheckConstraint('posts', 'chk_posts_status', "status IN ('draft', 'published')");
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
    }

    #[Test]
    public function ensureCheckConstraintSkipsMissingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureCheckConstraint('posts', 'chk_posts_status', "status IN ('draft', 'published')");
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
    }

    #[Test]
    public function ensureColumnCreatesNewColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getColumns')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureColumn('users', new Column\Varchar('email', 255));
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        static::assertNotEmpty($this->executedQueries);
        static::assertStringContainsString('ADD COLUMN', $this->executedQueries[0]);
    }

    #[Test]
    public function ensureColumnSkipsExistingColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $col = new ColumnObject('email', 'users');
        $col->setDataType('varchar');
        $metadata->method('getColumns')->willReturn([$col]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureColumn('users', new Column\Varchar('email', 255));
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function ensureForeignKeyCreatesWithOnDeleteAndOnUpdate(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureForeignKey(
                'posts',
                'fk_posts_user',
                'user_id',
                'users',
                'id',
                'CASCADE',
                'SET NULL',
            );
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('FOREIGN KEY');
        static::assertNotNull($ddlQuery);
        static::assertStringContainsString('ON DELETE CASCADE', $ddlQuery);
        static::assertStringContainsString('ON UPDATE SET NULL', $ddlQuery);
    }

    #[Test]
    public function ensureForeignKeySkipsExisting(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $constraint = new ConstraintObject('fk_posts_user', 'posts');
        $constraint->setType('FOREIGN KEY');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureForeignKey(
                'posts',
                'fk_posts_user',
                'user_id',
                'users',
                'id',
            );
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
    }

    #[Test]
    public function ensureIndexCreatesRegularIndex(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureIndex('users', 'idx_users_email', ['email']);
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('INDEX');
        static::assertNotNull($ddlQuery);
    }

    #[Test]
    public function ensureIndexCreatesUniqueIndex(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureIndex('users', 'uk_users_email', ['email'], true);
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('UNIQUE');
        static::assertNotNull($ddlQuery);
    }

    #[Test]
    public function ensureIndexSkipsExistingIndex(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $constraint = new ConstraintObject('idx_users_email', 'users');
        $constraint->setType('UNIQUE');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter, [
            ['Key_name' => 'idx_users_email', 'Column_name' => 'email'],
        ]);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureIndex('users', 'idx_users_email', ['email'], true);
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
    }

    #[Test]
    public function ensureTableCreatesNewTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureTable('users', static function (CreateTable $table): void {
                $table->addColumn(new Column\Integer('id'));
                $table->addColumn(new Column\Varchar('name', 255));
                $table->addConstraint(new Constraint\PrimaryKey(['id']));
            });
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        static::assertNotEmpty($result->executedSql);
        static::assertNotEmpty($this->executedQueries);
        static::assertStringContainsString('CREATE TABLE', $this->executedQueries[0]);
    }

    #[Test]
    public function ensureTableSkipsExistingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getColumns')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureTable('users', static function (CreateTable $table): void {
                $table->addColumn(new Column\Integer('id'));
            });
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function ensureTableWithAlterStrategyAltersMismatchedColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $col = new ColumnObject('email', 'users');
        $col->setDataType('varchar');
        $col->setCharacterMaximumLength(100);
        $metadata->method('getColumns')->willReturn([$col]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureTable('users', static function (CreateTable $table): void {
                $table->addColumn(new Column\Varchar('email', 255));
            });
        });

        $migration->setMismatchStrategy(MismatchStrategy::Alter);
        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        static::assertTrue($result->hasMismatches());
        $alterQuery = $this->findQuery('CHANGE');
        static::assertNotNull($alterQuery);
    }

    #[Test]
    public function ensureUniqueKeyCreatesNewKey(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureUniqueKey('users', 'uk_users_email', ['email']);
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('UNIQUE');
        static::assertNotNull($ddlQuery);
    }

    #[Test]
    public function ensureUniqueKeySkipsExisting(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $constraint = new ConstraintObject('uk_users_email', 'users');
        $constraint->setType('UNIQUE');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureUniqueKey('users', 'uk_users_email', ['email']);
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
    }

    #[Test]
    public function ensureUniqueKeySkipsMissingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureUniqueKey('users', 'uk_users_email', ['email']);
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
    }

    #[Test]
    public function executeSqlIfRunsWhenConditionTrue(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $adapter  = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callExecuteSqlIf(true, 'UPDATE users SET active = 1', 'Activate users');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        static::assertSame(['Activate users'], $result->executedSql);
    }

    #[Test]
    public function executeSqlIfSkipsSilentlyWithoutSkipMessage(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $adapter  = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callExecuteSqlIf(false, 'UPDATE users SET active = 1');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        static::assertSame([], $result->skippedOperations);
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function executeSqlIfSkipsWithMessageWhenConditionFalse(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $adapter  = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callExecuteSqlIf(false, 'UPDATE users SET active = 1', 'Activate users', 'Already active');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
        static::assertSame(['Already active'], $result->skippedOperations);
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function insertRowIfNotExistsFallsBackToInsertRowWhenUniqueColumnsAbsentFromData(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $adapter  = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callInsertRowIfNotExists('roles', ['name' => 'admin'], ['nonexistent_key']);
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $insertQuery = $this->findQuery('INSERT INTO');
        static::assertNotNull($insertQuery);
        static::assertNull($this->findQuery('SELECT COUNT'));
    }

    #[Test]
    public function insertRowIfNotExistsInsertsWhenNoMatch(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $adapter  = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callInsertRowIfNotExists('roles', ['name' => 'admin'], ['name']);
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $insertQuery = $this->findQuery('INSERT INTO');
        static::assertNotNull($insertQuery);
    }

    #[Test]
    public function insertRowIfNotExistsPreviewMode(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $adapter  = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callInsertRowIfNotExists('roles', ['name' => 'admin'], ['name']);
        });

        $preview = $migration->preview($adapter, $inspector);

        static::assertCount(1, $preview);
        static::assertStringContainsString('IF NOT EXISTS', $preview[0]);
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function insertRowIfNotExistsSkipsWhenMatchExists(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $adapter  = $this->createAdapter();

        $existingRow = $this->createMock(ResultSetInterface::class);
        $existingRow->method('current')->willReturn(['cnt' => 1]);
        $result = $this->createMock(ResultInterface::class);
        $result->method('getQueryResult')->willReturn($existingRow);
        $adapter->method('prepareQuery')->willReturn($this->createMock(StatementInterface::class));
        $adapter->method('executeQuery')->willReturn($result);

        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callInsertRowIfNotExists('roles', ['name' => 'admin'], ['name']);
        });

        $migrationResult = $migration->up($adapter, $inspector);

        static::assertTrue($migrationResult->isSkipped());
        static::assertSame(['Row in "roles" already exists (unique: name)'], $migrationResult->skippedOperations);
    }

    #[Test]
    public function insertRowInsertsData(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $adapter  = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callInsertRow('roles', ['name' => 'admin']);
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $insertQuery = $this->findQuery('INSERT INTO');
        static::assertNotNull($insertQuery);
    }

    #[Test]
    public function modifyColumnChangesColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $col = new ColumnObject('name', 'users');
        $col->setDataType('varchar');
        $metadata->method('getColumns')->willReturn([$col]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callModifyColumn('users', 'name', new Column\Varchar('name', 500));
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('CHANGE COLUMN');
        static::assertNotNull($ddlQuery);
    }

    #[Test]
    public function modifyColumnRenamesColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $col = new ColumnObject('name', 'users');
        $col->setDataType('varchar');
        $metadata->method('getColumns')->willReturn([$col]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callModifyColumn('users', 'name', new Column\Varchar('name', 255), 'full_name');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('CHANGE COLUMN');
        static::assertNotNull($ddlQuery);
        static::assertStringContainsString('full_name', $ddlQuery);
    }

    #[Test]
    public function modifyColumnSkipsMissingColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getColumns')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callModifyColumn('users', 'nonexistent', new Column\Varchar('nonexistent', 255));
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function modifyColumnSkipsMissingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callModifyColumn('nonexistent', 'name', new Column\Varchar('name', 255));
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isSkipped());
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function previewModeCollectsSqlWithoutExecuting(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callEnsureTable('users', static function (CreateTable $table): void {
                $table->addColumn(new Column\Integer('id'));
            });
        });

        $sql = $migration->preview($adapter, $inspector);

        static::assertNotEmpty($sql);
        static::assertStringContainsString('CREATE TABLE', $sql[0]);
        static::assertEmpty($this->executedQueries);
    }

    #[Test]
    public function upCatchesExceptionAndReturnsFailedResult(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(static function (AbstractMigration $m): void {
            $m->callExecuteSql('CREATE TABLE placeholder (id INT)', 'Create placeholder');
            throw new RuntimeException('Something broke mid-migration');
        });

        $result = $migration->up($adapter, $inspector);

        static::assertTrue($result->isFailed());
        static::assertSame('Something broke mid-migration', $result->errorMessage);
        static::assertSame(['Create placeholder'], $result->executedSql);
    }

    protected function setUp(): void
    {
        $this->executedQueries = [];
    }

    private function createAdapter(): AdapterInterface&MockObject
    {
        $adapter  = $this->createMock(AdapterInterface::class);
        $platform = $this->createMock(PlatformInterface::class);

        $sqlPlatformDecorator = new MysqlPlatform();

        $adapter->method('getPlatform')->willReturn($platform);
        $platform->method('getSqlPlatformDecorator')->willReturn($sqlPlatformDecorator);
        $platform->method('quoteIdentifier')
            ->willReturnCallback(static fn(string $id): string => "`{$id}`");
        $platform->method('quoteIdentifierChain')
            ->willReturnCallback(static fn(array $ids): string => '`' . implode('`.`', $ids) . '`');
        $platform->method('quoteIdentifierInFragment')
            ->willReturnCallback(static fn(string $id): string => "`{$id}`");

        return $adapter;
    }

    /** @param callable(TestableMigration): void $defineCallback */
    private function createMigration(callable $defineCallback): TestableMigration
    {
        return new TestableMigration($defineCallback);
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

    private function findQuery(string $substring): ?string
    {
        foreach ($this->executedQueries as $query) {
            if (str_contains($query, $substring)) {
                return $query;
            }
        }

        return null;
    }

    private function setupQueryCapture(AdapterInterface&MockObject $adapter): void
    {
        $adapter->method('prepareQuery')
            ->willReturnCallback(function (string $sql) {
                $this->executedQueries[] = $sql;
                return $this->createMock(StatementInterface::class);
            });
        $adapter->method('executeQuery')->willReturn($this->createResult());
    }

    /** @param array<array<string, string>> $indexRows */
    private function setupQueryCaptureWithShowIndex(
        AdapterInterface&MockObject $adapter,
        array $indexRows = [],
    ): void {
        $adapter->method('prepareQuery')
            ->willReturnCallback(function (string $sql) {
                $this->executedQueries[] = $sql;
                return $this->createMock(StatementInterface::class);
            });
        $adapter->method('executeQuery')
            ->willReturnCallback(function () use ($indexRows) {
                $sql = $this->executedQueries[count($this->executedQueries) - 1] ?? '';
                return str_contains($sql, 'SHOW INDEX')
                    ? $this->createResult($indexRows)
                    : $this->createResult();
            });
    }
}
