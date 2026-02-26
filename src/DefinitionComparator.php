<?php

declare(strict_types=1);

namespace PhpDb\Migration;

use PhpDb\Sql\Ddl\Column\AbstractLengthColumn;
use PhpDb\Sql\Ddl\Column\AbstractPrecisionColumn;
use PhpDb\Sql\Ddl\Column\BigInteger;
use PhpDb\Sql\Ddl\Column\Binary;
use PhpDb\Sql\Ddl\Column\Blob;
use PhpDb\Sql\Ddl\Column\Boolean;
use PhpDb\Sql\Ddl\Column\Char;
use PhpDb\Sql\Ddl\Column\ColumnInterface;
use PhpDb\Sql\Ddl\Column\Date;
use PhpDb\Sql\Ddl\Column\Datetime;
use PhpDb\Sql\Ddl\Column\Decimal;
use PhpDb\Sql\Ddl\Column\Double;
use PhpDb\Sql\Ddl\Column\Floating;
use PhpDb\Sql\Ddl\Column\Integer;
use PhpDb\Sql\Ddl\Column\Json;
use PhpDb\Sql\Ddl\Column\SmallInteger;
use PhpDb\Sql\Ddl\Column\Text;
use PhpDb\Sql\Ddl\Column\Time;
use PhpDb\Sql\Ddl\Column\Timestamp;
use PhpDb\Sql\Ddl\Column\Varbinary;
use PhpDb\Sql\Ddl\Column\Varchar;

use function array_diff;
use function implode;
use function strtolower;

/**
 * Compares existing schema against desired column/table definitions.
 *
 * Returns a list of mismatches describing what differs between
 * the existing schema and the desired definition.
 */
class DefinitionComparator
{
    /**
     * Compare an existing column (from schema inspection) against a desired DDL column.
     *
     * @param array<string, mixed> $existing Column details from SchemaInspector::getColumn()
     * @return array<array{table: string, column: string, field: string, expected: string, actual: string}>
     */
    public function compareColumn(string $tableName, array $existing, ColumnInterface $desired): array
    {
        $mismatches = [];
        $columnName = $desired->getName();

        // Compare data type
        $desiredType  = $this->resolveDataType($desired);
        $existingType = strtolower((string) ($existing['type'] ?? ''));

        if ($desiredType !== null && $existingType !== strtolower($desiredType)) {
            $mismatches[] = [
                'table'    => $tableName,
                'column'   => $columnName,
                'field'    => 'type',
                'expected' => $desiredType,
                'actual'   => $existingType,
            ];
        }

        // Compare nullable
        $desiredNullable  = $desired->isNullable();
        $existingNullable = $existing['nullable'] ?? null;

        if ($existingNullable !== null && $desiredNullable !== $existingNullable) {
            $mismatches[] = [
                'table'    => $tableName,
                'column'   => $columnName,
                'field'    => 'nullable',
                'expected' => $desiredNullable ? 'YES' : 'NO',
                'actual'   => $existingNullable ? 'YES' : 'NO',
            ];
        }

        // Compare length (for varchar, char, etc.)
        if ($desired instanceof AbstractLengthColumn) {
            $desiredLength  = $desired->getLength();
            $existingLength = $existing['maxLength'] ?? null;

            if ($desiredLength !== null && $existingLength !== null && $desiredLength !== $existingLength) {
                $mismatches[] = [
                    'table'    => $tableName,
                    'column'   => $columnName,
                    'field'    => 'length',
                    'expected' => (string) $desiredLength,
                    'actual'   => (string) $existingLength,
                ];
            }
        }

        // Compare precision and scale (for decimal, etc.)
        if ($desired instanceof AbstractPrecisionColumn) {
            $desiredPrecision  = $desired->getDecimal();
            $desiredDigits     = $desired->getDigits();
            $existingPrecision = $existing['numericPrecision'] ?? null;
            $existingScale     = $existing['numericScale'] ?? null;

            if ($desiredDigits !== null && $existingPrecision !== null && $desiredDigits !== $existingPrecision) {
                $mismatches[] = [
                    'table'    => $tableName,
                    'column'   => $columnName,
                    'field'    => 'precision',
                    'expected' => (string) $desiredDigits,
                    'actual'   => (string) $existingPrecision,
                ];
            }

            if ($desiredPrecision !== null && $existingScale !== null && $desiredPrecision !== $existingScale) {
                $mismatches[] = [
                    'table'    => $tableName,
                    'column'   => $columnName,
                    'field'    => 'scale',
                    'expected' => (string) $desiredPrecision,
                    'actual'   => (string) $existingScale,
                ];
            }
        }

        // Compare unsigned (for integer types, including BigInteger which extends Integer)
        if ($desired instanceof Integer) {
            $options          = $desired->getOptions();
            $desiredUnsigned  = $options['unsigned'] ?? null;
            $existingUnsigned = $existing['numericUnsigned'] ?? null;

            if ($desiredUnsigned !== null && $existingUnsigned !== null) {
                $desiredBool = (bool) $desiredUnsigned;
                if ($desiredBool !== $existingUnsigned) {
                    $mismatches[] = [
                        'table'    => $tableName,
                        'column'   => $columnName,
                        'field'    => 'unsigned',
                        'expected' => $desiredBool ? 'YES' : 'NO',
                        'actual'   => $existingUnsigned ? 'YES' : 'NO',
                    ];
                }
            }
        }

        return $mismatches;
    }

    /**
     * Compare index columns.
     *
     * @param array<string> $existingColumns
     * @param array<string> $desiredColumns
     * @return array<array{table: string, column: string, field: string, expected: string, actual: string}>
     */
    public function compareIndex(
        string $tableName,
        string $indexName,
        array $existingColumns,
        array $desiredColumns,
    ): array {
        $mismatches = [];

        $diff  = array_diff($desiredColumns, $existingColumns);
        $extra = array_diff($existingColumns, $desiredColumns);

        if ($diff !== [] || $extra !== []) {
            $mismatches[] = [
                'table'    => $tableName,
                'column'   => $indexName,
                'field'    => 'columns',
                'expected' => implode(', ', $desiredColumns),
                'actual'   => implode(', ', $existingColumns),
            ];
        }

        return $mismatches;
    }

    /**
     * Compare foreign key definition.
     *
     * @return array<array{table: string, column: string, field: string, expected: string, actual: string}>
     */
    public function compareForeignKey(
        string $tableName,
        string $constraintName,
        string $desiredColumn,
        string $desiredRefTable,
        string $desiredRefColumn,
        string $desiredOnDelete,
        string $desiredOnUpdate,
        string $existingColumn,
        string $existingRefTable,
        string $existingRefColumn,
        string $existingOnDelete,
        string $existingOnUpdate,
    ): array {
        $mismatches = [];

        if ($desiredColumn !== $existingColumn) {
            $mismatches[] = [
                'table'    => $tableName,
                'column'   => $constraintName,
                'field'    => 'column',
                'expected' => $desiredColumn,
                'actual'   => $existingColumn,
            ];
        }

        if ($desiredRefTable !== $existingRefTable) {
            $mismatches[] = [
                'table'    => $tableName,
                'column'   => $constraintName,
                'field'    => 'referenceTable',
                'expected' => $desiredRefTable,
                'actual'   => $existingRefTable,
            ];
        }

        if ($desiredRefColumn !== $existingRefColumn) {
            $mismatches[] = [
                'table'    => $tableName,
                'column'   => $constraintName,
                'field'    => 'referenceColumn',
                'expected' => $desiredRefColumn,
                'actual'   => $existingRefColumn,
            ];
        }

        if (strtolower($desiredOnDelete) !== strtolower($existingOnDelete)) {
            $mismatches[] = [
                'table'    => $tableName,
                'column'   => $constraintName,
                'field'    => 'onDelete',
                'expected' => $desiredOnDelete,
                'actual'   => $existingOnDelete,
            ];
        }

        if (strtolower($desiredOnUpdate) !== strtolower($existingOnUpdate)) {
            $mismatches[] = [
                'table'    => $tableName,
                'column'   => $constraintName,
                'field'    => 'onUpdate',
                'expected' => $desiredOnUpdate,
                'actual'   => $existingOnUpdate,
            ];
        }

        return $mismatches;
    }

    /**
     * Resolve the DDL column class to a SQL data type name.
     */
    private function resolveDataType(ColumnInterface $column): ?string
    {
        $options = $column->getOptions();

        if (isset($options['type'])) {
            return strtolower((string) $options['type']);
        }

        // Map known DDL column classes to data types
        $classMap = [
            BigInteger::class   => 'bigint',
            SmallInteger::class => 'smallint',
            Integer::class      => 'int',
            Varchar::class      => 'varchar',
            Char::class         => 'char',
            Text::class         => 'text',
            Blob::class         => 'blob',
            Boolean::class      => 'tinyint',
            Date::class         => 'date',
            Datetime::class     => 'datetime',
            Time::class         => 'time',
            Timestamp::class    => 'timestamp',
            Decimal::class      => 'decimal',
            Double::class       => 'double',
            Floating::class     => 'float',
            Varbinary::class    => 'varbinary',
            Binary::class       => 'binary',
            Json::class         => 'json',
        ];

        foreach ($classMap as $class => $type) {
            if ($column instanceof $class) {
                return $type;
            }
        }

        return null;
    }
}
