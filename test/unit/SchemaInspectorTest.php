<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use Exception;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Metadata\Object\ColumnObject;
use PhpDb\Metadata\Object\ConstraintObject;
use PhpDb\Migration\SchemaInspector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
        $this->adapter->method('query')
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

        $this->adapter->method('query')
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
}
