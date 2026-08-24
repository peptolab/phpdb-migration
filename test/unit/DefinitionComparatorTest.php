<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Migration\DefinitionComparator;
use PhpDb\Sql\Ddl\Column\BigInteger;
use PhpDb\Sql\Ddl\Column\Decimal;
use PhpDb\Sql\Ddl\Column\Double;
use PhpDb\Sql\Ddl\Column\Integer;
use PhpDb\Sql\Ddl\Column\Json;
use PhpDb\Sql\Ddl\Column\SmallInteger;
use PhpDb\Sql\Ddl\Column\Timestamp;
use PhpDb\Sql\Ddl\Column\Varbinary;
use PhpDb\Sql\Ddl\Column\Varchar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DefinitionComparatorTest extends TestCase
{
    private DefinitionComparator $comparator;

    #[Test]
    public function bigIntegerDoesNotResolveToInt(): void
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
        static::assertNotNull($typeMismatch);
        static::assertSame('bigint', $typeMismatch['expected']);
        static::assertSame('int', $typeMismatch['actual']);
    }

    #[Test]
    public function bigIntegerResolvesToBigint(): void
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

        static::assertSame([], $mismatches);
    }

    #[Test]
    public function compareColumnLengthMismatch(): void
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
        static::assertNotNull($lengthMismatch);
        static::assertSame('255', $lengthMismatch['expected']);
        static::assertSame('100', $lengthMismatch['actual']);
    }

    #[Test]
    public function compareColumnNoMismatch(): void
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

        static::assertSame([], $mismatches);
    }

    #[Test]
    public function compareColumnNullableMismatch(): void
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
        static::assertNotNull($nullMismatch);
        static::assertSame('NO', $nullMismatch['expected']);
        static::assertSame('YES', $nullMismatch['actual']);
    }

    #[Test]
    public function compareColumnPrecisionAndScaleMismatch(): void
    {
        $existing = [
            'name'             => 'amount',
            'type'             => 'decimal',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => 8,
            'numericScale'     => 1,
            'numericUnsigned'  => null,
        ];

        $desired = new Decimal('amount', digits: 10, decimal: 2);

        $mismatches = $this->comparator->compareColumn('invoices', $existing, $desired);

        $precisionMismatch = $this->findMismatch($mismatches, 'precision');
        static::assertNotNull($precisionMismatch);
        static::assertSame('10', $precisionMismatch['expected']);
        static::assertSame('8', $precisionMismatch['actual']);

        $scaleMismatch = $this->findMismatch($mismatches, 'scale');
        static::assertNotNull($scaleMismatch);
        static::assertSame('2', $scaleMismatch['expected']);
        static::assertSame('1', $scaleMismatch['actual']);
    }

    #[Test]
    public function compareColumnPrecisionAndScaleNoMismatch(): void
    {
        $existing = [
            'name'             => 'amount',
            'type'             => 'decimal',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => 10,
            'numericScale'     => 2,
            'numericUnsigned'  => null,
        ];

        $desired = new Decimal('amount', digits: 10, decimal: 2);

        static::assertSame([], $this->comparator->compareColumn('invoices', $existing, $desired));
    }

    #[Test]
    public function compareColumnTypeMismatch(): void
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

        static::assertNotEmpty($mismatches);

        $typeMismatch = $this->findMismatch($mismatches, 'type');
        static::assertNotNull($typeMismatch);
        static::assertSame('int', $typeMismatch['expected']);
        static::assertSame('bigint', $typeMismatch['actual']);
    }

    #[Test]
    public function compareColumnUnsignedMismatch(): void
    {
        $existing = [
            'name'             => 'count',
            'type'             => 'int',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => false,
        ];

        $desired = new Integer('count');
        $desired->setOption('unsigned', true);

        $mismatches = $this->comparator->compareColumn('stats', $existing, $desired);

        $unsignedMismatch = $this->findMismatch($mismatches, 'unsigned');
        static::assertNotNull($unsignedMismatch);
        static::assertSame('YES', $unsignedMismatch['expected']);
        static::assertSame('NO', $unsignedMismatch['actual']);
    }

    #[Test]
    public function compareColumnUnsignedNoMismatchWhenBothMatch(): void
    {
        $existing = [
            'name'             => 'count',
            'type'             => 'int',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => true,
        ];

        $desired = new Integer('count');
        $desired->setOption('unsigned', true);

        static::assertSame([], $this->comparator->compareColumn('stats', $existing, $desired));
    }

    #[Test]
    public function compareColumnUnsignedSkippedWhenExistingUnsignedUnknown(): void
    {
        $existing = [
            'name'             => 'count',
            'type'             => 'int',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => null,
        ];

        $desired = new Integer('count');
        $desired->setOption('unsigned', true);

        static::assertSame([], $this->comparator->compareColumn('stats', $existing, $desired));
    }

    #[Test]
    public function compareColumnUsesExplicitTypeOption(): void
    {
        $existing = [
            'name'             => 'status',
            'type'             => 'enum',
            'nullable'         => false,
            'default'          => null,
            'maxLength'        => null,
            'numericPrecision' => null,
            'numericScale'     => null,
            'numericUnsigned'  => null,
        ];

        $desired = new Varchar('status', 20);
        $desired->setOption('type', 'ENUM');

        static::assertSame([], $this->comparator->compareColumn('orders', $existing, $desired));
    }

    #[Test]
    public function compareForeignKeyNoMismatch(): void
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

        static::assertSame([], $mismatches);
    }

    #[Test]
    public function compareForeignKeyOnDeleteMismatch(): void
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
        static::assertNotNull($deleteMismatch);
        static::assertSame('CASCADE', $deleteMismatch['expected']);
        static::assertSame('RESTRICT', $deleteMismatch['actual']);
    }

    #[Test]
    public function compareForeignKeyRefTableMismatch(): void
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

        static::assertNotEmpty($mismatches);

        $refMismatch = $this->findMismatch($mismatches, 'referenceTable');
        static::assertNotNull($refMismatch);
        static::assertSame('users', $refMismatch['expected']);
        static::assertSame('authors', $refMismatch['actual']);
    }

    #[Test]
    public function compareIndexColumnMismatch(): void
    {
        $mismatches = $this->comparator->compareIndex(
            'users',
            'idx_users_name_email',
            ['name'],
            ['name', 'email'],
        );

        static::assertNotEmpty($mismatches);
        static::assertSame('columns', $mismatches[0]['field']);
        static::assertSame('name, email', $mismatches[0]['expected']);
        static::assertSame('name', $mismatches[0]['actual']);
    }

    #[Test]
    public function compareIndexNoMismatch(): void
    {
        $mismatches = $this->comparator->compareIndex(
            'users',
            'idx_users_email',
            ['email'],
            ['email'],
        );

        static::assertSame([], $mismatches);
    }

    #[Test]
    public function doubleResolvesToDouble(): void
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

        static::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    #[Test]
    public function jsonResolvesToJson(): void
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

        static::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    #[Test]
    public function smallIntegerResolvesToSmallint(): void
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

        static::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    #[Test]
    public function timestampResolvesToTimestamp(): void
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

        static::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    #[Test]
    public function varbinaryResolvesToVarbinary(): void
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

        static::assertSame([], $this->comparator->compareColumn('data', $existing, $desired));
    }

    protected function setUp(): void
    {
        $this->comparator = new DefinitionComparator();
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
