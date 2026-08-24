<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use Exception;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Adapter\Driver\StatementInterface;
use PhpDb\Adapter\Platform\PlatformInterface;
use PhpDb\Adapter\SchemaAwareInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Metadata\Object\ColumnObject;
use PhpDb\Metadata\Object\ConstraintObject;
use PhpDb\Migration\SchemaInspector;
use PhpDb\Mysql\Metadata\Source as MysqlMetadataSource;
use PhpDb\ResultSet\ResultSetInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class SchemaInspectorTest extends TestCase
{
    private AdapterInterface&MockObject $adapter;
    private MetadataInterface&MockObject $metadata;
    private SchemaInspector $inspector;

    #[Test]
    public function clearCacheResetsAllCaches(): void
    {
        // Create a fresh inspector with a mock that tracks call count
        $metadata  = $this->createMock(MetadataInterface::class);
        $inspector = new SchemaInspector($this->adapter, $metadata);

        $metadata->expects(self::exactly(2))
            ->method('getTableNames')
            ->willReturn(['users']);

        // First call caches the result
        static::assertTrue($inspector->tableExists('users'));

        // Clear cache - forces re-query on next call
        $inspector->clearCache();

        // Second call must re-query metadata (cache was cleared)
        static::assertTrue($inspector->tableExists('users'));
    }

    #[Test]
    public function columnExistsReturnsFalseWhenColumnMissing(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);

        $column = new ColumnObject('name', 'users');
        $column->setDataType('varchar');

        $this->metadata
            ->method('getColumns')
            ->with('users')
            ->willReturn([$column]);

        static::assertFalse($this->inspector->columnExists('users', 'email'));
    }

    #[Test]
    public function columnExistsReturnsFalseWhenTableMissing(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        static::assertFalse($this->inspector->columnExists('users', 'email'));
    }

    #[Test]
    public function columnExistsReturnsTrue(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);

        $column = new ColumnObject('email', 'users');
        $column->setDataType('varchar');

        $this->metadata
            ->method('getColumns')
            ->with('users')
            ->willReturn([$column]);

        static::assertTrue($this->inspector->columnExists('users', 'email'));
    }

    #[Test]
    public function constraintExistsResolvesLaminasPrefixedConstraintName(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['parent_table']);

        // php-db/phpdb-mysql's metadata source synthesizes non-FK constraint
        // names as "_laminas_{table}_{name}" internally.
        $constraint = new ConstraintObject('_laminas_parent_table_chk_parent_name', 'parent_table');
        $constraint->setType('CHECK');
        $this->metadata
            ->method('getConstraints')
            ->with('parent_table')
            ->willReturn([$constraint]);

        $this->adapter->method('prepareQuery')->willThrowException(new Exception('Not supported'));

        static::assertTrue($this->inspector->constraintExists('parent_table', 'chk_parent_name'));
    }

    #[Test]
    public function constraintExistsReturnsFalseWhenTableMissing(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        static::assertFalse($this->inspector->constraintExists('users', 'uk_users_email'));
    }

    #[Test]
    public function constraintExistsReturnsTrue(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);

        $constraint = new ConstraintObject('uk_users_email', 'users');
        $constraint->setType('UNIQUE');

        $this->metadata
            ->method('getConstraints')
            ->with('users')
            ->willReturn([$constraint]);

        // Mock the adapter query for SHOW INDEX to avoid errors
        $this->adapter->method('prepareQuery')->willThrowException(new Exception('Not supported'));

        static::assertTrue($this->inspector->constraintExists('users', 'uk_users_email'));
    }

    #[Test]
    public function createMetadataFromAdapterResolvesMysqlPlatform(): void
    {
        $adapter = $this->createMockForIntersectionOfInterfaces([
            AdapterInterface::class,
            SchemaAwareInterface::class,
        ]);
        $adapter->method('getCurrentSchema')->willReturn('test_schema');

        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getName')->willReturn('MySQL');
        $adapter->method('getPlatform')->willReturn($platform);

        $inspector = new SchemaInspector($adapter);

        $method = new ReflectionMethod(SchemaInspector::class, 'createMetadataFromAdapter');
        $method->setAccessible(true);

        static::assertInstanceOf(MysqlMetadataSource::class, $method->invoke($inspector));
    }

    #[Test]
    public function foreignKeyExistsIsAliasForConstraintExists(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['posts']);

        $constraint = new ConstraintObject('fk_posts_user', 'posts');
        $constraint->setType('FOREIGN KEY');

        $this->metadata
            ->method('getConstraints')
            ->with('posts')
            ->willReturn([$constraint]);

        $this->adapter->method('prepareQuery')->willThrowException(new Exception('Not supported'));

        static::assertTrue($this->inspector->foreignKeyExists('posts', 'fk_posts_user'));
    }

    #[Test]
    public function getAdapter(): void
    {
        static::assertSame($this->adapter, $this->inspector->getAdapter());
    }

    #[Test]
    public function getColumnReturnsDetails(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);

        $column = new ColumnObject('email', 'users');
        $column->setDataType('varchar');
        $column->setIsNullable(false);
        $column->setCharacterMaximumLength(255);

        $this->metadata
            ->method('getColumns')
            ->with('users')
            ->willReturn([$column]);

        $details = $this->inspector->getColumn('users', 'email');

        static::assertNotNull($details);
        static::assertSame('email', $details['name']);
        static::assertSame('varchar', $details['type']);
        static::assertFalse($details['nullable']);
        static::assertSame(255, $details['maxLength']);
    }

    #[Test]
    public function getColumnReturnsNullWhenNotFound(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);

        $this->metadata
            ->method('getColumns')
            ->with('users')
            ->willReturn([]);

        static::assertNull($this->inspector->getColumn('users', 'missing'));
    }

    #[Test]
    public function getColumnsReturnsEmptyForMissingTable(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        static::assertSame([], $this->inspector->getColumns('nonexistent'));
    }

    #[Test]
    public function getConstraintsReturnsEmptyForMissingTable(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        static::assertSame([], $this->inspector->getConstraints('nonexistent'));
    }

    #[Test]
    public function getConstraintsReturnsExistingConstraints(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);

        $constraint = new ConstraintObject('pk_users', 'users');
        $this->metadata
            ->method('getConstraints')
            ->with('users')
            ->willReturn([$constraint]);

        static::assertSame([$constraint], $this->inspector->getConstraints('users'));
    }

    #[Test]
    public function getTableNames(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users', 'posts', 'comments']);

        $names = $this->inspector->getTableNames();

        static::assertSame(['users', 'posts', 'comments'], $names);
    }

    #[Test]
    public function indexExistsReturnsFalseWhenIndexMissing(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);
        $this->metadata->method('getConstraints')->willReturn([]);
        $this->stubShowIndex([]);

        static::assertFalse($this->inspector->indexExists('users', 'idx_users_missing'));
    }

    #[Test]
    public function indexExistsReturnsFalseWhenTableMissing(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        static::assertFalse($this->inspector->indexExists('users', 'idx_users_email'));
    }

    #[Test]
    public function indexExistsReturnsTrue(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);
        $this->metadata->method('getConstraints')->willReturn([]);
        $this->stubShowIndex([['Key_name' => 'idx_users_email']]);

        static::assertTrue($this->inspector->indexExists('users', 'idx_users_email'));
    }

    #[Test]
    public function lazilyCreatesMetadataWhenNoneInjectedAndPlatformIsUnsupported(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->method('getName')->willReturn('FooBar');

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('getPlatform')->willReturn($platform);

        $inspector = new SchemaInspector($adapter);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to create metadata source for platform 'FooBar'");

        $inspector->tableExists('users');
    }

    #[Test]
    public function markTableCreated(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        static::assertFalse($this->inspector->tableExists('new_table'));

        $this->inspector->markTableCreated('new_table');

        static::assertTrue($this->inspector->tableExists('new_table'));
    }

    #[Test]
    public function tableExistsCachesResult(): void
    {
        $this->metadata
            ->expects(self::once())
            ->method('getTableNames')
            ->willReturn(['users']);

        // Call twice - should only query metadata once
        $this->inspector->tableExists('users');
        $this->inspector->tableExists('users');
    }

    #[Test]
    public function tableExistsReturnsFalse(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users', 'posts']);

        static::assertFalse($this->inspector->tableExists('comments'));
    }

    #[Test]
    public function tableExistsReturnsTrue(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users', 'posts']);

        static::assertTrue($this->inspector->tableExists('users'));
    }

    protected function setUp(): void
    {
        $this->adapter   = $this->createMock(AdapterInterface::class);
        $this->metadata  = $this->createMock(MetadataInterface::class);
        $this->inspector = new SchemaInspector($this->adapter, $this->metadata);
    }

    /** @param array<array<string, string>> $rows */
    private function stubShowIndex(array $rows): void
    {
        $this->adapter->method('prepareQuery')->willReturn($this->createMock(StatementInterface::class));

        $resultSet = $this->createMock(ResultSetInterface::class);
        $resultSet->method('toArray')->willReturn($rows);

        $result = $this->createMock(ResultInterface::class);
        $result->method('getQueryResult')->willReturn($resultSet);

        $this->adapter->method('executeQuery')->willReturn($result);
    }
}
