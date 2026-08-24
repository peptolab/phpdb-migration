<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Asset;

use PhpDb\Migration\AbstractMigration;
use PhpDb\Sql\Ddl\Column;
use PhpDb\Sql\Ddl\CreateTable;

class TestableMigration extends AbstractMigration
{
    /** @var callable(TestableMigration): void */
    private $defineCallback;

    /**
     * @param callable(TestableMigration): void $defineCallback
     */
    public function __construct(callable $defineCallback)
    {
        $this->defineCallback = $defineCallback;
    }

    public function getVersion(): string
    {
        return '20260101000000';
    }

    public function getDescription(): string
    {
        return 'Test migration';
    }

    protected function define(): void
    {
        ($this->defineCallback)($this);
    }

    /** @param callable(CreateTable): void $callback */
    public function callEnsureTable(string $tableName, callable $callback): void
    {
        $this->ensureTable($tableName, $callback);
    }

    public function callEnsureColumn(string $tableName, Column\ColumnInterface $column): void
    {
        $this->ensureColumn($tableName, $column);
    }

    /** @param array<string> $columns */
    public function callEnsureIndex(
        string $tableName,
        string $indexName,
        array $columns,
        bool $unique = false,
    ): void {
        $this->ensureIndex($tableName, $indexName, $columns, $unique);
    }

    public function callEnsureForeignKey(
        string $tableName,
        string $constraintName,
        string $column,
        string $referenceTable,
        string $referenceColumn,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT',
    ): void {
        $this->ensureForeignKey(
            $tableName,
            $constraintName,
            $column,
            $referenceTable,
            $referenceColumn,
            $onDelete,
            $onUpdate,
        );
    }

    public function callDropIndexIfExists(string $tableName, string $indexName): void
    {
        $this->dropIndexIfExists($tableName, $indexName);
    }

    public function callDropForeignKeyIfExists(string $tableName, string $constraintName): void
    {
        $this->dropForeignKeyIfExists($tableName, $constraintName);
    }

    public function callModifyColumn(
        string $tableName,
        string $columnName,
        Column\Column $column,
        ?string $newName = null,
    ): void {
        $this->modifyColumn($tableName, $columnName, $column, $newName);
    }
}
