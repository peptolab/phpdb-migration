<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Adapter\AdapterInterface;
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
use PhpDb\Sql\Platform\AbstractPlatform;
use PhpDbTest\Migration\Asset\TestableMigration;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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

    private function createAdapter(): AdapterInterface&MockObject
    {
        $adapter  = $this->createMock(AdapterInterface::class);
        $platform = $this->createMock(PlatformInterface::class);

        $sqlPlatformDecorator = $this->createMock(AbstractPlatform::class);
        $sqlPlatformDecorator->method('getDecorators')->willReturn([]);

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

    private function setupQueryCapture(AdapterInterface&MockObject $adapter): void
    {
        $adapter->method('query')
            ->willReturnCallback(function (string $sql) {
                $this->executedQueries[] = $sql;
                return $this->createResultSet();
            });
    }

    /** @param array<array<string, string>> $indexRows */
    private function setupQueryCaptureWithShowIndex(
        AdapterInterface&MockObject $adapter,
        array $indexRows = [],
    ): void {
        $adapter->method('query')
            ->willReturnCallback(function (string $sql) use ($indexRows) {
                $this->executedQueries[] = $sql;
                if (str_contains($sql, 'SHOW INDEX')) {
                    return $this->createResultSet($indexRows);
                }
                return $this->createResultSet();
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
