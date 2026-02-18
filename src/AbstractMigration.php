<?php

declare(strict_types=1);

namespace PhpDb\Migration;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Sql\Ddl\AlterTable;
use PhpDb\Sql\Ddl\Column\ColumnInterface;
use PhpDb\Sql\Ddl\Constraint;
use PhpDb\Sql\Ddl\CreateTable;
use PhpDb\Sql\Ddl\DropTable;
use PhpDb\Sql\Sql;
use PhpDb\Sql\SqlInterface;
use Throwable;

use function array_fill;
use function array_keys;
use function array_merge;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * Base class for migrations providing idempotent helper methods.
 *
 * Subclasses implement the define() method to specify schema changes.
 * All operations check current schema state before executing.
 */
abstract class AbstractMigration implements MigrationInterface
{
    protected AdapterInterface $adapter;

    protected SchemaInspector $inspector;

    /** @var array<string> SQL statements executed */
    protected array $executedSql = [];

    /** @var array<string> Operations that were skipped */
    protected array $skippedOperations = [];

    /** @var array<string> SQL statements for preview mode */
    protected array $previewSql = [];

    /** @var bool Whether we're in preview mode */
    protected bool $previewMode = false;

    /** @var MismatchStrategy Strategy for handling definition mismatches */
    protected MismatchStrategy $mismatchStrategy = MismatchStrategy::Report;

    protected DefinitionComparator $comparator;

    /** @var array<array{table: string, column: string, field: string, expected: string, actual: string}> */
    protected array $mismatches = [];

    /**
     * Define the migration operations.
     *
     * Subclasses implement this method and call helper methods like
     * ensureTable(), ensureColumn(), etc.
     */
    abstract protected function define(): void;

    /**
     * Set the mismatch strategy (called by MigrationRunner before up()).
     */
    public function setMismatchStrategy(MismatchStrategy $strategy): void
    {
        $this->mismatchStrategy = $strategy;
    }

    public function up(AdapterInterface $adapter, SchemaInspector $inspector): MigrationResult
    {
        $this->adapter           = $adapter;
        $this->inspector         = $inspector;
        $this->previewMode       = false;
        $this->executedSql       = [];
        $this->skippedOperations = [];
        $this->mismatches        = [];
        $this->comparator        = new DefinitionComparator();

        try {
            $this->define();

            if (empty($this->executedSql) && ! empty($this->skippedOperations)) {
                return MigrationResult::skipped($this->skippedOperations, $this->mismatches);
            }

            return MigrationResult::success($this->executedSql, $this->skippedOperations, $this->mismatches);
        } catch (Throwable $e) {
            return MigrationResult::failed($e->getMessage(), $this->executedSql);
        }
    }

    public function preview(AdapterInterface $adapter, SchemaInspector $inspector): array
    {
        $this->adapter     = $adapter;
        $this->inspector   = $inspector;
        $this->previewMode = true;
        $this->previewSql  = [];
        $this->mismatches  = [];
        $this->comparator  = new DefinitionComparator();

        $this->define();

        return $this->previewSql;
    }

    /**
     * Create a table if it doesn't exist.
     *
     * If the table exists and mismatch strategy is not Ignore, each column defined
     * in the callback will be compared against the existing schema.
     *
     * @param callable(CreateTable): void $callback Function to define table columns and constraints
     */
    protected function ensureTable(string $tableName, callable $callback): void
    {
        if ($this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf('Table "%s" already exists', $tableName);

            if ($this->mismatchStrategy !== MismatchStrategy::Ignore && ! $this->previewMode) {
                $this->checkTableDefinition($tableName, $callback);
            }

            return;
        }

        $table = new CreateTable($tableName);
        $callback($table);

        $this->executeDdl($table, sprintf('Create table "%s"', $tableName));
    }

    /**
     * Add a column to a table if it doesn't exist.
     *
     * If the column exists and mismatch strategy is not Ignore, the definition
     * will be compared against the existing schema.
     */
    protected function ensureColumn(string $tableName, ColumnInterface $column): void
    {
        $columnName = $column->getName();

        if (! $this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf(
                'Column "%s.%s" skipped - table does not exist',
                $tableName,
                $columnName,
            );

            return;
        }

        if ($this->inspector->columnExists($tableName, $columnName)) {
            $this->skippedOperations[] = sprintf(
                'Column "%s.%s" already exists',
                $tableName,
                $columnName,
            );

            if ($this->mismatchStrategy !== MismatchStrategy::Ignore && ! $this->previewMode) {
                $this->checkColumnDefinition($tableName, $column);
            }

            return;
        }

        $alter = new AlterTable($tableName);
        $alter->addColumn($column);

        $this->executeDdl($alter, sprintf('Add column "%s" to "%s"', $columnName, $tableName));
    }

    /**
     * Add an index to a table if it doesn't exist.
     *
     * If the index exists and mismatch strategy is not Ignore, the column list
     * will be compared against the existing index.
     *
     * @param array<string> $columns
     */
    protected function ensureIndex(
        string $tableName,
        string $indexName,
        array $columns,
        bool $unique = false,
    ): void {
        if (! $this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf(
                'Index "%s.%s" skipped - table does not exist',
                $tableName,
                $indexName,
            );

            return;
        }

        if ($this->inspector->indexExists($tableName, $indexName)) {
            $this->skippedOperations[] = sprintf('Index "%s.%s" already exists', $tableName, $indexName);

            if ($this->mismatchStrategy !== MismatchStrategy::Ignore && ! $this->previewMode) {
                $this->checkIndexDefinition($tableName, $indexName, $columns);
            }

            return;
        }

        // PhpDb DDL doesn't support CREATE INDEX directly via AlterTable
        // We need to use raw SQL
        $columnList = '`' . implode('`, `', $columns) . '`';
        $indexType  = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $sql        = sprintf(
            'CREATE %s `%s` ON `%s` (%s)',
            $indexType,
            $indexName,
            $tableName,
            $columnList,
        );

        $this->executeSql($sql, sprintf('Add index "%s" to "%s"', $indexName, $tableName));
    }

    /**
     * Add a unique constraint to a table if it doesn't exist.
     *
     * @param array<string> $columns
     */
    protected function ensureUniqueKey(string $tableName, string $keyName, array $columns): void
    {
        if (! $this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf(
                'Unique key "%s.%s" skipped - table does not exist',
                $tableName,
                $keyName,
            );

            return;
        }

        if ($this->inspector->constraintExists($tableName, $keyName)) {
            $this->skippedOperations[] = sprintf(
                'Unique key "%s.%s" already exists',
                $tableName,
                $keyName,
            );

            return;
        }

        $alter = new AlterTable($tableName);
        $alter->addConstraint(new Constraint\UniqueKey($columns, $keyName));

        $this->executeDdl($alter, sprintf('Add unique key "%s" to "%s"', $keyName, $tableName));
    }

    /**
     * Add a foreign key to a table if it doesn't exist.
     *
     * If the FK exists and mismatch strategy is not Ignore, the reference details
     * will be compared against the existing constraint.
     */
    protected function ensureForeignKey(
        string $tableName,
        string $constraintName,
        string $column,
        string $referenceTable,
        string $referenceColumn,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT',
    ): void {
        if (! $this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf(
                'Foreign key "%s.%s" skipped - table does not exist',
                $tableName,
                $constraintName,
            );

            return;
        }

        if ($this->inspector->constraintExists($tableName, $constraintName)) {
            $this->skippedOperations[] = sprintf(
                'Foreign key "%s.%s" already exists',
                $tableName,
                $constraintName,
            );

            if ($this->mismatchStrategy !== MismatchStrategy::Ignore && ! $this->previewMode) {
                $this->checkForeignKeyDefinition(
                    $tableName,
                    $constraintName,
                    $column,
                    $referenceTable,
                    $referenceColumn,
                    $onDelete,
                    $onUpdate,
                );
            }

            return;
        }

        // Use raw SQL for foreign key with ON DELETE/UPDATE options
        // as PhpDb DDL has limited support for these options
        $sql = sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s',
            $tableName,
            $constraintName,
            $column,
            $referenceTable,
            $referenceColumn,
            $onDelete,
            $onUpdate,
        );

        $this->executeSql($sql, sprintf('Add foreign key "%s" to "%s"', $constraintName, $tableName));
    }

    /**
     * Drop a table if it exists.
     */
    protected function dropTableIfExists(string $tableName): void
    {
        if (! $this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf('Table "%s" does not exist', $tableName);

            return;
        }

        $drop = new DropTable($tableName);
        $this->executeDdl($drop, sprintf('Drop table "%s"', $tableName));
    }

    /**
     * Drop a column if it exists.
     */
    protected function dropColumnIfExists(string $tableName, string $columnName): void
    {
        if (! $this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf(
                'Column "%s.%s" drop skipped - table does not exist',
                $tableName,
                $columnName,
            );

            return;
        }

        if (! $this->inspector->columnExists($tableName, $columnName)) {
            $this->skippedOperations[] = sprintf(
                'Column "%s.%s" does not exist',
                $tableName,
                $columnName,
            );

            return;
        }

        $alter = new AlterTable($tableName);
        $alter->dropColumn($columnName);

        $this->executeDdl($alter, sprintf('Drop column "%s" from "%s"', $columnName, $tableName));
    }

    /**
     * Drop an index if it exists.
     */
    protected function dropIndexIfExists(string $tableName, string $indexName): void
    {
        if (! $this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf(
                'Index "%s.%s" drop skipped - table does not exist',
                $tableName,
                $indexName,
            );

            return;
        }

        if (! $this->inspector->indexExists($tableName, $indexName)) {
            $this->skippedOperations[] = sprintf('Index "%s.%s" does not exist', $tableName, $indexName);

            return;
        }

        $sql = sprintf('DROP INDEX `%s` ON `%s`', $indexName, $tableName);
        $this->executeSql($sql, sprintf('Drop index "%s" from "%s"', $indexName, $tableName));
    }

    /**
     * Drop a foreign key if it exists.
     */
    protected function dropForeignKeyIfExists(string $tableName, string $constraintName): void
    {
        if (! $this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf(
                'Foreign key "%s.%s" drop skipped - table does not exist',
                $tableName,
                $constraintName,
            );

            return;
        }

        if (! $this->inspector->constraintExists($tableName, $constraintName)) {
            $this->skippedOperations[] = sprintf(
                'Foreign key "%s.%s" does not exist',
                $tableName,
                $constraintName,
            );

            return;
        }

        $sql = sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $constraintName);
        $this->executeSql($sql, sprintf('Drop foreign key "%s" from "%s"', $constraintName, $tableName));
    }

    /**
     * Execute raw SQL.
     *
     * @param string      $sql         The SQL statement to execute
     * @param string|null $description Optional description for logging
     */
    protected function executeSql(string $sql, ?string $description = null): void
    {
        if ($this->previewMode) {
            $this->previewSql[] = $sql;

            return;
        }

        $this->adapter->query($sql, []);
        $this->executedSql[] = $description ?? $sql;
    }

    /**
     * Execute SQL only if a condition is true.
     *
     * @param bool        $condition   The condition to check
     * @param string      $sql         The SQL statement to execute
     * @param string|null $description Optional description for logging
     * @param string|null $skipMessage Message to log if condition is false
     */
    protected function executeSqlIf(
        bool $condition,
        string $sql,
        ?string $description = null,
        ?string $skipMessage = null,
    ): void {
        if (! $condition) {
            if ($skipMessage !== null) {
                $this->skippedOperations[] = $skipMessage;
            }

            return;
        }

        $this->executeSql($sql, $description);
    }

    /**
     * Execute a DDL object.
     */
    protected function executeDdl(SqlInterface $ddl, ?string $description = null): void
    {
        $sql       = new Sql($this->adapter);
        $sqlString = $sql->buildSqlString($ddl);

        if ($this->previewMode) {
            $this->previewSql[] = $sqlString;

            return;
        }

        $this->adapter->query($sqlString, []);
        $this->executedSql[] = $description ?? $sqlString;
    }

    /**
     * Modify an existing column.
     *
     * Note: This uses raw SQL as PhpDb DDL's CHANGE COLUMN support is limited.
     */
    protected function modifyColumn(
        string $tableName,
        string $columnName,
        string $columnDefinition,
        ?string $newName = null,
    ): void {
        if (! $this->inspector->tableExists($tableName)) {
            $this->skippedOperations[] = sprintf(
                'Modify column "%s.%s" skipped - table does not exist',
                $tableName,
                $columnName,
            );

            return;
        }

        if (! $this->inspector->columnExists($tableName, $columnName)) {
            $this->skippedOperations[] = sprintf(
                'Modify column "%s.%s" skipped - column does not exist',
                $tableName,
                $columnName,
            );

            return;
        }

        $targetName = $newName ?? $columnName;
        $sql        = sprintf(
            'ALTER TABLE `%s` CHANGE `%s` `%s` %s',
            $tableName,
            $columnName,
            $targetName,
            $columnDefinition,
        );

        $this->executeSql(
            $sql,
            sprintf('Modify column "%s" in "%s"', $columnName, $tableName),
        );
    }

    /**
     * Insert data into a table (for seed data in migrations).
     *
     * @param array<string, mixed> $data
     */
    protected function insertRow(string $tableName, array $data): void
    {
        $columns      = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $values       = array_values($data);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $tableName,
            implode('`, `', $columns),
            implode(', ', $placeholders),
        );

        if ($this->previewMode) {
            // Show a more readable preview
            $preview            = sprintf(
                'INSERT INTO `%s` (`%s`) VALUES (...)',
                $tableName,
                implode('`, `', $columns),
            );
            $this->previewSql[] = $preview;

            return;
        }

        $this->adapter->query($sql, $values);
        $this->executedSql[] = sprintf('Insert row into "%s"', $tableName);
    }

    /**
     * Insert data only if a matching row doesn't exist.
     *
     * @param array<string, mixed> $data
     * @param array<string>        $uniqueColumns Columns to check for existing row
     */
    protected function insertRowIfNotExists(
        string $tableName,
        array $data,
        array $uniqueColumns,
    ): void {
        // Build WHERE clause for uniqueness check
        $conditions = [];
        $params     = [];

        foreach ($uniqueColumns as $col) {
            if (! isset($data[$col])) {
                continue;
            }
            $conditions[] = "`{$col}` = ?";
            $params[]     = $data[$col];
        }

        if (empty($conditions)) {
            $this->insertRow($tableName, $data);

            return;
        }

        $checkSql = sprintf(
            'SELECT COUNT(*) as cnt FROM `%s` WHERE %s',
            $tableName,
            implode(' AND ', $conditions),
        );

        if ($this->previewMode) {
            $this->previewSql[] = sprintf(
                'INSERT INTO `%s` IF NOT EXISTS (check: %s)',
                $tableName,
                implode(' AND ', $conditions),
            );

            return;
        }

        $result = $this->adapter->query($checkSql, $params)->current();

        if (($result['cnt'] ?? 0) > 0) {
            $this->skippedOperations[] = sprintf(
                'Row in "%s" already exists (unique: %s)',
                $tableName,
                implode(', ', $uniqueColumns),
            );

            return;
        }

        $this->insertRow($tableName, $data);
    }

    /**
     * Check table definition against existing schema and handle mismatches.
     *
     * @param callable(CreateTable): void $callback
     */
    private function checkTableDefinition(string $tableName, callable $callback): void
    {
        // Create a temporary table object to capture column definitions
        $table = new CreateTable($tableName);
        $callback($table);

        // Extract columns from the CreateTable via raw state
        $rawState = $table->getRawState();
        $columns  = $rawState[CreateTable::COLUMNS] ?? [];

        foreach ($columns as $column) {
            if (! $column instanceof ColumnInterface) {
                continue;
            }

            if (! $this->inspector->columnExists($tableName, $column->getName())) {
                continue;
            }

            $this->checkColumnDefinition($tableName, $column);
        }
    }

    /**
     * Check a single column definition and handle mismatches per strategy.
     */
    private function checkColumnDefinition(string $tableName, ColumnInterface $column): void
    {
        $existing = $this->inspector->getColumn($tableName, $column->getName());

        if ($existing === null) {
            return;
        }

        $columnMismatches = $this->comparator->compareColumn($tableName, $existing, $column);

        if ($columnMismatches === []) {
            return;
        }

        $this->mismatches = array_merge($this->mismatches, $columnMismatches);

        if ($this->mismatchStrategy === MismatchStrategy::Alter) {
            $alter = new AlterTable($tableName);
            $alter->changeColumn($column->getName(), $column);
            $this->executeDdl(
                $alter,
                sprintf('Alter column "%s" in "%s" to match definition', $column->getName(), $tableName),
            );
        }
    }

    /**
     * Check index columns against existing schema.
     *
     * @param array<string> $desiredColumns
     */
    private function checkIndexDefinition(string $tableName, string $indexName, array $desiredColumns): void
    {
        // Get existing index columns via SHOW INDEX
        $existingColumns = [];

        try {
            $sql    = sprintf("SHOW INDEX FROM `%s` WHERE Key_name = '%s'", $tableName, $indexName);
            $result = $this->adapter->query($sql, [])->toArray();

            foreach ($result as $row) {
                $existingColumns[] = $row['Column_name'];
            }
        } catch (Throwable) {
            return;
        }

        if ($existingColumns === []) {
            return;
        }

        $indexMismatches = $this->comparator->compareIndex($tableName, $indexName, $existingColumns, $desiredColumns);

        if ($indexMismatches === []) {
            return;
        }

        $this->mismatches = array_merge($this->mismatches, $indexMismatches);

        if ($this->mismatchStrategy === MismatchStrategy::Alter) {
            // Drop and recreate the index
            $dropSql = sprintf('DROP INDEX `%s` ON `%s`', $indexName, $tableName);
            $this->executeSql($dropSql, sprintf('Drop index "%s" from "%s" for recreation', $indexName, $tableName));

            $columnList = '`' . implode('`, `', $desiredColumns) . '`';
            $createSql  = sprintf('CREATE INDEX `%s` ON `%s` (%s)', $indexName, $tableName, $columnList);
            $this->executeSql($createSql, sprintf('Recreate index "%s" on "%s"', $indexName, $tableName));
        }
    }

    /**
     * Check foreign key definition against existing schema.
     */
    private function checkForeignKeyDefinition(
        string $tableName,
        string $constraintName,
        string $desiredColumn,
        string $desiredRefTable,
        string $desiredRefColumn,
        string $desiredOnDelete,
        string $desiredOnUpdate,
    ): void {
        $constraints = $this->inspector->getConstraints($tableName);

        foreach ($constraints as $constraint) {
            if ($constraint->getName() !== $constraintName) {
                continue;
            }

            $existingColumns    = $constraint->getColumns();
            $existingRefTable   = $constraint->getReferencedTableName() ?? '';
            $existingRefColumns = $constraint->getReferencedColumns() ?? [];
            $existingOnDelete   = $constraint->getDeleteRule() ?? 'RESTRICT';
            $existingOnUpdate   = $constraint->getUpdateRule() ?? 'RESTRICT';

            $fkMismatches = $this->comparator->compareForeignKey(
                $tableName,
                $constraintName,
                $desiredColumn,
                $desiredRefTable,
                $desiredRefColumn,
                $desiredOnDelete,
                $desiredOnUpdate,
                $existingColumns[0] ?? '',
                $existingRefTable,
                $existingRefColumns[0] ?? '',
                $existingOnDelete,
                $existingOnUpdate,
            );

            if ($fkMismatches === []) {
                return;
            }

            $this->mismatches = array_merge($this->mismatches, $fkMismatches);

            if ($this->mismatchStrategy === MismatchStrategy::Alter) {
                // Drop and recreate the FK
                $dropSql = sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $constraintName);
                $this->executeSql(
                    $dropSql,
                    sprintf('Drop foreign key "%s" from "%s" for recreation', $constraintName, $tableName),
                );

                $createSql = sprintf(
                    'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) '
                    . 'REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s',
                    $tableName,
                    $constraintName,
                    $desiredColumn,
                    $desiredRefTable,
                    $desiredRefColumn,
                    $desiredOnDelete,
                    $desiredOnUpdate,
                );
                $this->executeSql(
                    $createSql,
                    sprintf('Recreate foreign key "%s" on "%s"', $constraintName, $tableName),
                );
            }

            return;
        }
    }
}
