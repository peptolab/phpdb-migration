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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class SchemaInspectorTest extends TestCase
{
    private AdapterInterface&MockObject $adapter;
    private MetadataInterface&MockObject $metadata;
    private SchemaInspector $inspector;

    protected function setUp(): void
    {
        $this->adapter   = $this->createMock(AdapterInterface::class);
        $this->metadata  = $this->createMock(MetadataInterface::class);
        $this->inspector = new SchemaInspector($this->adapter, $this->metadata);
    }

    public function testTableExistsReturnsTrue(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn(['users', 'posts']);

        self::assertTrue($this->inspector->tableExists('users'));
    }

    public function testTableExistsReturnsFalse(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn(['users', 'posts']);

        self::assertFalse($this->inspector->tableExists('comments'));
    }

    public function testTableExistsCachesResult(): void
    {
        $this->metadata->expects(self::once())
            ->method('getTableNames')
            ->willReturn(['users']);

        // Call twice - should only query metadata once
        $this->inspector->tableExists('users');
        $this->inspector->tableExists('users');
    }

    public function testColumnExistsReturnsTrue(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn(['users']);

        $column = new ColumnObject('email', 'users');
        $column->setDataType('varchar');

        $this->metadata->method('getColumns')
            ->with('users')
            ->willReturn([$column]);

        self::assertTrue($this->inspector->columnExists('users', 'email'));
    }

    public function testColumnExistsReturnsFalseWhenTableMissing(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn([]);

        self::assertFalse($this->inspector->columnExists('users', 'email'));
    }

    public function testColumnExistsReturnsFalseWhenColumnMissing(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn(['users']);

        $column = new ColumnObject('name', 'users');
        $column->setDataType('varchar');

        $this->metadata->method('getColumns')
            ->with('users')
            ->willReturn([$column]);

        self::assertFalse($this->inspector->columnExists('users', 'email'));
    }

    public function testGetColumnReturnsDetails(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn(['users']);

        $column = new ColumnObject('email', 'users');
        $column->setDataType('varchar');
        $column->setIsNullable(false);
        $column->setCharacterMaximumLength(255);

        $this->metadata->method('getColumns')
            ->with('users')
            ->willReturn([$column]);

        $details = $this->inspector->getColumn('users', 'email');

        self::assertNotNull($details);
        self::assertSame('email', $details['name']);
        self::assertSame('varchar', $details['type']);
        self::assertFalse($details['nullable']);
        self::assertSame(255, $details['maxLength']);
    }

    public function testGetColumnReturnsNullWhenNotFound(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn(['users']);

        $this->metadata->method('getColumns')
            ->with('users')
            ->willReturn([]);

        self::assertNull($this->inspector->getColumn('users', 'missing'));
    }

    public function testGetColumnsReturnsEmptyForMissingTable(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn([]);

        self::assertSame([], $this->inspector->getColumns('nonexistent'));
    }

    public function testConstraintExistsReturnsTrue(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn(['users']);

        $constraint = new ConstraintObject('uk_users_email', 'users');
        $constraint->setType('UNIQUE');

        $this->metadata->method('getConstraints')
            ->with('users')
            ->willReturn([$constraint]);

        // Mock the adapter query for SHOW INDEX to avoid errors
        $this->adapter->method('prepareQuery')
            ->willThrowException(new Exception('Not supported'));

        self::assertTrue($this->inspector->constraintExists('users', 'uk_users_email'));
    }

    public function testConstraintExistsReturnsFalseWhenTableMissing(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn([]);

        self::assertFalse($this->inspector->constraintExists('users', 'uk_users_email'));
    }

    public function testForeignKeyExistsIsAliasForConstraintExists(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn(['posts']);

        $constraint = new ConstraintObject('fk_posts_user', 'posts');
        $constraint->setType('FOREIGN KEY');

        $this->metadata->method('getConstraints')
            ->with('posts')
            ->willReturn([$constraint]);

        $this->adapter->method('prepareQuery')
            ->willThrowException(new Exception('Not supported'));

        self::assertTrue($this->inspector->foreignKeyExists('posts', 'fk_posts_user'));
    }

    public function testClearCacheResetsAllCaches(): void
    {
        // Create a fresh inspector with a mock that tracks call count
        $metadata  = $this->createMock(MetadataInterface::class);
        $inspector = new SchemaInspector($this->adapter, $metadata);

        $metadata->expects(self::exactly(2))
            ->method('getTableNames')
            ->willReturn(['users']);

        // First call caches the result
        self::assertTrue($inspector->tableExists('users'));

        // Clear cache - forces re-query on next call
        $inspector->clearCache();

        // Second call must re-query metadata (cache was cleared)
        self::assertTrue($inspector->tableExists('users'));
    }

    public function testGetAdapter(): void
    {
        self::assertSame($this->adapter, $this->inspector->getAdapter());
    }

    public function testGetTableNames(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn(['users', 'posts', 'comments']);

        $names = $this->inspector->getTableNames();

        self::assertSame(['users', 'posts', 'comments'], $names);
    }

    public function testGetConstraintsReturnsEmptyForMissingTable(): void
    {
        $this->metadata->method('getTableNames')
            ->willReturn([]);

        self::assertSame([], $this->inspector->getConstraints('nonexistent'));
    }

    public function testGetConstraintsReturnsExistingConstraints(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);

        $constraint = new ConstraintObject('pk_users', 'users');
        $this->metadata->method('getConstraints')
            ->with('users')
            ->willReturn([$constraint]);

        self::assertSame([$constraint], $this->inspector->getConstraints('users'));
    }

    public function testMarkTableCreated(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        self::assertFalse($this->inspector->tableExists('new_table'));

        $this->inspector->markTableCreated('new_table');

        self::assertTrue($this->inspector->tableExists('new_table'));
    }

    public function testIndexExistsReturnsTrue(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);
        $this->metadata->method('getConstraints')->willReturn([]);
        $this->stubShowIndex([['Key_name' => 'idx_users_email']]);

        self::assertTrue($this->inspector->indexExists('users', 'idx_users_email'));
    }

    public function testIndexExistsReturnsFalseWhenTableMissing(): void
    {
        $this->metadata->method('getTableNames')->willReturn([]);

        self::assertFalse($this->inspector->indexExists('users', 'idx_users_email'));
    }

    public function testIndexExistsReturnsFalseWhenIndexMissing(): void
    {
        $this->metadata->method('getTableNames')->willReturn(['users']);
        $this->metadata->method('getConstraints')->willReturn([]);
        $this->stubShowIndex([]);

        self::assertFalse($this->inspector->indexExists('users', 'idx_users_missing'));
    }

    public function testLazilyCreatesMetadataWhenNoneInjectedAndPlatformIsUnsupported(): void
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

    public function testCreateMetadataFromAdapterResolvesMysqlPlatform(): void
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

        self::assertInstanceOf(MysqlMetadataSource::class, $method->invoke($inspector));
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
