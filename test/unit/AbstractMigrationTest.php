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
use PhpDb\ResultSet\ResultSetInterface;
use PhpDb\Sql\Ddl\Column;
use PhpDb\Sql\Ddl\Constraint;
use PhpDb\Sql\Ddl\CreateTable;
use PhpDb\Mysql\Sql\Platform as MysqlPlatform;
use PhpDbTest\Migration\Asset\TestableMigration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function implode;
use function str_contains;

class AbstractMigrationTest extends TestCase
{
    /** @var array<string> */
    private array $executedQueries = [];

    protected function setUp(): void
    {
        $this->executedQueries = [];
    }

    public function testEnsureTableCreatesNewTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureTable('users', function (CreateTable $table): void {
                $table->addColumn(new Column\Integer('id'));
                $table->addColumn(new Column\Varchar('name', 255));
                $table->addConstraint(new Constraint\PrimaryKey(['id']));
            });
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        self::assertNotEmpty($result->executedSql);
        self::assertNotEmpty($this->executedQueries);
        self::assertStringContainsString('CREATE TABLE', $this->executedQueries[0]);
    }

    public function testEnsureTableSkipsExistingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getColumns')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureTable('users', function (CreateTable $table): void {
                $table->addColumn(new Column\Integer('id'));
            });
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
        self::assertEmpty($this->executedQueries);
    }

    public function testEnsureColumnCreatesNewColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getColumns')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureColumn('users', new Column\Varchar('email', 255));
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        self::assertNotEmpty($this->executedQueries);
        self::assertStringContainsString('ADD COLUMN', $this->executedQueries[0]);
    }

    public function testEnsureColumnSkipsExistingColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $col = new ColumnObject('email', 'users');
        $col->setDataType('varchar');
        $metadata->method('getColumns')->willReturn([$col]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureColumn('users', new Column\Varchar('email', 255));
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
        self::assertEmpty($this->executedQueries);
    }

    public function testEnsureIndexCreatesRegularIndex(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureIndex('users', 'idx_users_email', ['email']);
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('INDEX');
        self::assertNotNull($ddlQuery);
    }

    public function testEnsureIndexCreatesUniqueIndex(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureIndex('users', 'uk_users_email', ['email'], true);
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('UNIQUE');
        self::assertNotNull($ddlQuery);
    }

    public function testEnsureIndexSkipsExistingIndex(): void
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

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureIndex('users', 'idx_users_email', ['email'], true);
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
    }

    public function testEnsureCheckConstraintCreatesNewConstraint(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureCheckConstraint('posts', 'chk_posts_status', "status IN ('draft', 'published')");
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('CHECK');
        self::assertNotNull($ddlQuery);
        self::assertStringContainsString('chk_posts_status', $ddlQuery);
    }

    public function testEnsureCheckConstraintSkipsExisting(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $constraint = new ConstraintObject('chk_posts_status', 'posts');
        $constraint->setType('CHECK');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureCheckConstraint('posts', 'chk_posts_status', "status IN ('draft', 'published')");
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
    }

    public function testEnsureCheckConstraintSkipsMissingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureCheckConstraint('posts', 'chk_posts_status', "status IN ('draft', 'published')");
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
    }

    public function testEnsureForeignKeyCreatesWithOnDeleteAndOnUpdate(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
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

        self::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('FOREIGN KEY');
        self::assertNotNull($ddlQuery);
        self::assertStringContainsString('ON DELETE CASCADE', $ddlQuery);
        self::assertStringContainsString('ON UPDATE SET NULL', $ddlQuery);
    }

    public function testEnsureForeignKeySkipsExisting(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $constraint = new ConstraintObject('fk_posts_user', 'posts');
        $constraint->setType('FOREIGN KEY');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureForeignKey(
                'posts',
                'fk_posts_user',
                'user_id',
                'users',
                'id',
            );
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
    }

    public function testDropIndexIfExistsDropsExistingIndex(): void
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

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callDropIndexIfExists('users', 'idx_users_email');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('DROP INDEX');
        self::assertNotNull($ddlQuery);
    }

    public function testDropIndexIfExistsSkipsMissing(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callDropIndexIfExists('users', 'idx_nonexistent');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
    }

    public function testDropForeignKeyIfExistsDropsExistingConstraint(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $constraint = new ConstraintObject('fk_posts_user', 'posts');
        $constraint->setType('FOREIGN KEY');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callDropForeignKeyIfExists('posts', 'fk_posts_user');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('DROP CONSTRAINT');
        self::assertNotNull($ddlQuery);
    }

    public function testDropForeignKeyIfExistsSkipsMissing(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['posts']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callDropForeignKeyIfExists('posts', 'fk_nonexistent');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
    }

    public function testModifyColumnChangesColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $col = new ColumnObject('name', 'users');
        $col->setDataType('varchar');
        $metadata->method('getColumns')->willReturn([$col]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callModifyColumn('users', 'name', new Column\Varchar('name', 500));
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('CHANGE COLUMN');
        self::assertNotNull($ddlQuery);
    }

    public function testModifyColumnRenamesColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $col = new ColumnObject('name', 'users');
        $col->setDataType('varchar');
        $metadata->method('getColumns')->willReturn([$col]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callModifyColumn('users', 'name', new Column\Varchar('name', 255), 'full_name');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('CHANGE COLUMN');
        self::assertNotNull($ddlQuery);
        self::assertStringContainsString('full_name', $ddlQuery);
    }

    public function testModifyColumnSkipsMissingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callModifyColumn('nonexistent', 'name', new Column\Varchar('name', 255));
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
        self::assertEmpty($this->executedQueries);
    }

    public function testModifyColumnSkipsMissingColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getColumns')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callModifyColumn('users', 'nonexistent', new Column\Varchar('nonexistent', 255));
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
        self::assertEmpty($this->executedQueries);
    }

    public function testPreviewModeCollectsSqlWithoutExecuting(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureTable('users', function (CreateTable $table): void {
                $table->addColumn(new Column\Integer('id'));
            });
        });

        $sql = $migration->preview($adapter, $inspector);

        self::assertNotEmpty($sql);
        self::assertStringContainsString('CREATE TABLE', $sql[0]);
        self::assertEmpty($this->executedQueries);
    }

    public function testUpCatchesExceptionAndReturnsFailedResult(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callExecuteSql('CREATE TABLE placeholder (id INT)', 'Create placeholder');
            throw new RuntimeException('Something broke mid-migration');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isFailed());
        self::assertSame('Something broke mid-migration', $result->errorMessage);
        self::assertSame(['Create placeholder'], $result->executedSql);
    }

    public function testCheckIndexDefinitionWithAlterStrategy(): void
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

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureIndex('users', 'idx_users_name', ['name', 'email']);
        });

        $migration->setMismatchStrategy(MismatchStrategy::Alter);
        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->hasMismatches());
        $dropQuery = $this->findQuery('DROP INDEX');
        self::assertNotNull($dropQuery);
    }

    public function testCheckForeignKeyDefinitionWithAlterStrategy(): void
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

        $migration = $this->createMigration(function (AbstractMigration $m): void {
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

        self::assertTrue($result->hasMismatches());
        $dropQuery = $this->findQuery('DROP CONSTRAINT');
        self::assertNotNull($dropQuery);
        $addQuery = $this->findQuery('FOREIGN KEY');
        self::assertNotNull($addQuery);
    }

    public function testEnsureTableWithAlterStrategyAltersMismatchedColumn(): void
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

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureTable('users', function (CreateTable $table): void {
                $table->addColumn(new Column\Varchar('email', 255));
            });
        });

        $migration->setMismatchStrategy(MismatchStrategy::Alter);
        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        self::assertTrue($result->hasMismatches());
        $alterQuery = $this->findQuery('CHANGE');
        self::assertNotNull($alterQuery);
    }

    public function testEnsureUniqueKeyCreatesNewKey(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getConstraints')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureUniqueKey('users', 'uk_users_email', ['email']);
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $ddlQuery = $this->findQuery('UNIQUE');
        self::assertNotNull($ddlQuery);
    }

    public function testEnsureUniqueKeySkipsExisting(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $constraint = new ConstraintObject('uk_users_email', 'users');
        $constraint->setType('UNIQUE');
        $metadata->method('getConstraints')->willReturn([$constraint]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureUniqueKey('users', 'uk_users_email', ['email']);
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
    }

    public function testEnsureUniqueKeySkipsMissingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCaptureWithShowIndex($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callEnsureUniqueKey('users', 'uk_users_email', ['email']);
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
    }

    public function testDropTableIfExistsDropsExistingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callDropTableIfExists('users');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $dropQuery = $this->findQuery('DROP TABLE');
        self::assertNotNull($dropQuery);
    }

    public function testDropTableIfExistsSkipsMissing(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callDropTableIfExists('users');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
        self::assertEmpty($this->executedQueries);
    }

    public function testDropColumnIfExistsDropsExistingColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $col = new ColumnObject('nickname', 'users');
        $col->setDataType('varchar');
        $metadata->method('getColumns')->willReturn([$col]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callDropColumnIfExists('users', 'nickname');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $dropQuery = $this->findQuery('DROP COLUMN');
        self::assertNotNull($dropQuery);
    }

    public function testDropColumnIfExistsSkipsMissingTable(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callDropColumnIfExists('users', 'nickname');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
    }

    public function testDropColumnIfExistsSkipsMissingColumn(): void
    {
        $metadata = $this->createMock(MetadataInterface::class);
        $metadata->method('getTableNames')->willReturn(['users']);
        $metadata->method('getColumns')->willReturn([]);
        $adapter = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callDropColumnIfExists('users', 'nickname');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
        self::assertEmpty($this->executedQueries);
    }

    public function testExecuteSqlIfRunsWhenConditionTrue(): void
    {
        $metadata  = $this->createMock(MetadataInterface::class);
        $adapter   = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callExecuteSqlIf(true, 'UPDATE users SET active = 1', 'Activate users');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        self::assertSame(['Activate users'], $result->executedSql);
    }

    public function testExecuteSqlIfSkipsWithMessageWhenConditionFalse(): void
    {
        $metadata  = $this->createMock(MetadataInterface::class);
        $adapter   = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callExecuteSqlIf(false, 'UPDATE users SET active = 1', 'Activate users', 'Already active');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSkipped());
        self::assertSame(['Already active'], $result->skippedOperations);
        self::assertEmpty($this->executedQueries);
    }

    public function testExecuteSqlIfSkipsSilentlyWithoutSkipMessage(): void
    {
        $metadata  = $this->createMock(MetadataInterface::class);
        $adapter   = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callExecuteSqlIf(false, 'UPDATE users SET active = 1');
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->skippedOperations);
        self::assertEmpty($this->executedQueries);
    }

    public function testInsertRowInsertsData(): void
    {
        $metadata  = $this->createMock(MetadataInterface::class);
        $adapter   = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callInsertRow('roles', ['name' => 'admin']);
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $insertQuery = $this->findQuery('INSERT INTO');
        self::assertNotNull($insertQuery);
    }

    public function testInsertRowIfNotExistsInsertsWhenNoMatch(): void
    {
        $metadata  = $this->createMock(MetadataInterface::class);
        $adapter   = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callInsertRowIfNotExists('roles', ['name' => 'admin'], ['name']);
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $insertQuery = $this->findQuery('INSERT INTO');
        self::assertNotNull($insertQuery);
    }

    public function testInsertRowIfNotExistsSkipsWhenMatchExists(): void
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

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callInsertRowIfNotExists('roles', ['name' => 'admin'], ['name']);
        });

        $migrationResult = $migration->up($adapter, $inspector);

        self::assertTrue($migrationResult->isSkipped());
        self::assertSame(['Row in "roles" already exists (unique: name)'], $migrationResult->skippedOperations);
    }

    public function testInsertRowIfNotExistsFallsBackToInsertRowWhenUniqueColumnsAbsentFromData(): void
    {
        $metadata  = $this->createMock(MetadataInterface::class);
        $adapter   = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callInsertRowIfNotExists('roles', ['name' => 'admin'], ['nonexistent_key']);
        });

        $result = $migration->up($adapter, $inspector);

        self::assertTrue($result->isSuccess());
        $insertQuery = $this->findQuery('INSERT INTO');
        self::assertNotNull($insertQuery);
        self::assertNull($this->findQuery('SELECT COUNT'));
    }

    public function testInsertRowIfNotExistsPreviewMode(): void
    {
        $metadata  = $this->createMock(MetadataInterface::class);
        $adapter   = $this->createAdapter();
        $this->setupQueryCapture($adapter);
        $inspector = new SchemaInspector($adapter, $metadata);

        $migration = $this->createMigration(function (AbstractMigration $m): void {
            $m->callInsertRowIfNotExists('roles', ['name' => 'admin'], ['name']);
        });

        $preview = $migration->preview($adapter, $inspector);

        self::assertCount(1, $preview);
        self::assertStringContainsString('IF NOT EXISTS', $preview[0]);
        self::assertEmpty($this->executedQueries);
    }

    private function createAdapter(): AdapterInterface&MockObject
    {
        $adapter  = $this->createMock(AdapterInterface::class);
        $platform = $this->createMock(PlatformInterface::class);

        $sqlPlatformDecorator = new MysqlPlatform();

        $adapter->method('getPlatform')->willReturn($platform);
        $platform->method('getSqlPlatformDecorator')->willReturn($sqlPlatformDecorator);
        $platform->method('quoteIdentifier')
            ->willReturnCallback(fn (string $id): string => '`' . $id . '`');
        $platform->method('quoteIdentifierChain')
            ->willReturnCallback(fn (array $ids): string => '`' . implode('`.`', $ids) . '`');
        $platform->method('quoteIdentifierInFragment')
            ->willReturnCallback(fn (string $id): string => '`' . $id . '`');

        return $adapter;
    }

    private function createResultSet(array $rows = []): ResultSetInterface&MockObject
    {
        $resultSet = $this->createMock(ResultSetInterface::class);
        $resultSet->method('toArray')->willReturn($rows);

        return $resultSet;
    }

    /** @param array<array<string, mixed>> $rows */
    private function createResult(array $rows = []): ResultInterface&MockObject
    {
        $result = $this->createMock(ResultInterface::class);
        $result->method('getQueryResult')->willReturn($this->createResultSet($rows));

        return $result;
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

    private function findQuery(string $substring): ?string
    {
        foreach ($this->executedQueries as $query) {
            if (str_contains($query, $substring)) {
                return $query;
            }
        }

        return null;
    }

    /** @param callable(TestableMigration): void $defineCallback */
    private function createMigration(callable $defineCallback): TestableMigration
    {
        return new TestableMigration($defineCallback);
    }
}
