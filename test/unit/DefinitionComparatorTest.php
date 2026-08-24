<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Migration\DefinitionComparator;
use PhpDb\Sql\Ddl\Column\BigInteger;
use PhpDb\Sql\Ddl\Column\Double;
use PhpDb\Sql\Ddl\Column\Integer;
use PhpDb\Sql\Ddl\Column\Json;
use PhpDb\Sql\Ddl\Column\SmallInteger;
use PhpDb\Sql\Ddl\Column\Timestamp;
use PhpDb\Sql\Ddl\Column\Varbinary;
use PhpDb\Sql\Ddl\Column\Varchar;
use PHPUnit\Framework\TestCase;

class DefinitionComparatorTest extends TestCase
{
    private DefinitionComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new DefinitionComparator();
    }

    public function testCompareColumnNoMismatch(): void
    {
        $existing = [
            'name'             => 'email',
            'type'             => 'varchar',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => 255,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => null,
        ];

        $desired = new Varchar('email', 255);

        $mismatches = $this->comparator->compareColumn('users', $existing, $desired);

        self::assertSame([], $mismatches);
    }

    public function testCompareColumnTypeMismatch(): void
    {
        $existing = [
            'name'             => 'count',
            'type'             => 'bigint',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => 20,
            'numericScale'     => 0,
            'numericUnsigned'  => false,
        ];

        $desired = new Integer('count');

        $mismatches = $this->comparator->compareColumn('stats', $existing, $desired);

        self::assertNotEmpty($mismatches);

        $typeMismatch = $this->findMismatch($mismatches, 'type');
        self::assertNotNull($typeMismatch);
        self::assertSame('int', $typeMismatch['expected']);
        self::assertSame('bigint', $typeMismatch['actual']);
    }

    public function testCompareColumnLengthMismatch(): void
    {
        $existing = [
            'name'             => 'name',
            'type'             => 'varchar',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => 100,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => null,
        ];

        $desired = new Varchar('name', 255);

        $mismatches = $this->comparator->compareColumn('users', $existing, $desired);

        $lengthMismatch = $this->findMismatch($mismatches, 'length');
        self::assertNotNull($lengthMismatch);
        self::assertSame('255', $lengthMismatch['expected']);
        self::assertSame('100', $lengthMismatch['actual']);
    }

    public function testCompareColumnNullableMismatch(): void
    {
        $existing = [
            'name'             => 'bio',
            'type'             => 'varchar',
            'nullable'         => true,
            'default'          => null,
            'maxLength'        => 255,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => null,
        ];

        $desired = new Varchar('bio', 255);
        // Default ColumnInterface isNullable is false

        $mismatches = $this->comparator->compareColumn('users', $existing, $desired);

        $nullMismatch = $this->findMismatch($mismatches, 'nullable');
        self::assertNotNull($nullMismatch);
        self::assertSame('NO', $nullMismatch['expected']);
        self::assertSame('YES', $nullMismatch['actual']);
    }

    public function testCompareIndexNoMismatch(): void
    {
        $mismatches = $this->comparator->compareIndex(
            'users',
            'idx_users_email',
            ['email'],
            ['email'],
        );

        self::assertSame([], $mismatches);
    }

    public function testCompareIndexColumnMismatch(): void
    {
        $mismatches = $this->comparator->compareIndex(
            'users',
            'idx_users_name_email',
            ['name'],
            ['name', 'email'],
        );

        self::assertNotEmpty($mismatches);
        self::assertSame('columns', $mismatches[0]['field']);
        self::assertSame('name, email', $mismatches[0]['expected']);
        self::assertSame('name', $mismatches[0]['actual']);
    }

    public function testCompareForeignKeyNoMismatch(): void
    {
        $mismatches = $this->comparator->compareForeignKey(
            'posts',
            'fk_posts_user',
            'user_id',
            'users',
            'id',
            'CASCADE',
            'RESTRICT',
            'user_id',
            'users',
            'id',
            'CASCADE',
            'RESTRICT',
        );

        self::assertSame([], $mismatches);
    }

    public function testCompareForeignKeyRefTableMismatch(): void
    {
        $mismatches = $this->comparator->compareForeignKey(
            'posts',
            'fk_posts_author',
            'author_id',
            'users',
            'id',
            'CASCADE',
            'RESTRICT',
            'author_id',
            'authors',
            'id',
            'CASCADE',
            'RESTRICT',
        );

        self::assertNotEmpty($mismatches);

        $refMismatch = $this->findMismatch($mismatches, 'referenceTable');
        self::assertNotNull($refMismatch);
        self::assertSame('users', $refMismatch['expected']);
        self::assertSame('authors', $refMismatch['actual']);
    }

    public function testCompareForeignKeyOnDeleteMismatch(): void
    {
        $mismatches = $this->comparator->compareForeignKey(
            'posts',
            'fk_posts_user',
            'user_id',
            'users',
            'id',
            'CASCADE',
            'RESTRICT',
            'user_id',
            'users',
            'id',
            'RESTRICT',
            'RESTRICT',
        );

        $deleteMismatch = $this->findMismatch($mismatches, 'onDelete');
        self::assertNotNull($deleteMismatch);
        self::assertSame('CASCADE', $deleteMismatch['expected']);
        self::assertSame('RESTRICT', $deleteMismatch['actual']);
    }

    public function testBigIntegerResolvesToBigint(): void
    {
        $existing = [
            'name'             => 'big_id',
            'type'             => 'bigint',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => 20,
            'numericScale'     => 0,
            'numericUnsigned'  => false,
        ];

        $desired = new BigInteger('big_id');

        $mismatches = $this->comparator->compareColumn('data', $existing, $desired);

        self::assertSame([], $mismatches);
    }

    public function testBigIntegerDoesNotResolveToInt(): void
    {
        $existing = [
            'name'             => 'big_id',
            'type'             => 'int',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => 10,
            'numericScale'     => 0,
            'numericUnsigned'  => false,
        ];

        $desired = new BigInteger('big_id');

        $mismatches = $this->comparator->compareColumn('data', $existing, $desired);

        $typeMismatch = $this->findMismatch($mismatches, 'type');
        self::assertNotNull($typeMismatch);
        self::assertSame('bigint', $typeMismatch['expected']);
        self::assertSame('int', $typeMismatch['actual']);
    }

    public function testSmallIntegerResolvesToSmallint(): void
    {
        $existing = [
            'name'             => 'small_val',
            'type'             => 'smallint',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => 5,
            'numericScale'     => 0,
            'numericUnsigned'  => false,
        ];

        $desired = new SmallInteger('small_val');

        self::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    public function testDoubleResolvesToDouble(): void
    {
        $existing = [
            'name'             => 'amount',
            'type'             => 'double',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => null,
        ];

        $desired = new Double('amount');

        self::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    public function testJsonResolvesToJson(): void
    {
        $existing = [
            'name'             => 'payload',
            'type'             => 'json',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => null,
        ];

        $desired = new Json('payload');

        self::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    public function testTimestampResolvesToTimestamp(): void
    {
        $existing = [
            'name'             => 'created_at',
            'type'             => 'timestamp',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => null,
        ];

        $desired = new Timestamp('created_at');

        self::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    public function testVarbinaryResolvesToVarbinary(): void
    {
        $existing = [
            'name'             => 'hash',
            'type'             => 'varbinary',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => 32,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => null,
        ];

        $desired = new Varbinary('hash', 32);

        self::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    /**
     * @param array<array{table: string, column: string, field: string, expected: string, actual: string}> $mismatches
     * @return array{table: string, column: string, field: string, expected: string, actual: string}|null
     */
    private function findMismatch(array $mismatches, string $field): ?array
    {
        foreach ($mismatches as $mismatch) {
            if ($mismatch['field'] === $field) {
                return $mismatch;
            }
        }

        return null;
    }
}
