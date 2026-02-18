<?php

declare(strict_types=1);

namespace PhpDb\Migration;

use Exception;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Metadata\Object\ConstraintObject;
use RuntimeException;

use function array_key_exists;
use function call_user_func;
use function class_exists;
use function in_array;

/**
 * Schema introspection wrapper with caching.
 *
 * Provides convenient methods to check schema state for idempotent migrations.
 * Results are cached within the instance to avoid repeated database queries.
 */
class SchemaInspector
{
    private ?MetadataInterface $metadata = null;

    private readonly ?MetadataInterface $injectedMetadata;

    /** @var array<string, bool> */
    private array $tableCache = [];

    /** @var array<string, array<string, bool>> */
    private array $columnCache = [];

    /** @var array<string, array<string, bool>> */
    private array $indexCache = [];

    /** @var array<string, array<string, bool>> */
    private array $constraintCache = [];

    /** @var array<string, array<string, mixed>> */
    private array $columnDetailsCache = [];

    public function __construct(
        private readonly AdapterInterface $adapter,
        ?MetadataInterface $metadata = null,
    ) {
        $this->injectedMetadata = $metadata;
        $this->metadata         = $metadata;
    }

    /**
     * Check if a table exists.
     */
    public function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableCache)) {
            return $this->tableCache[$tableName];
        }

        $tables                       = $this->getMetadata()->getTableNames();
        $exists                       = in_array($tableName, $tables, true);
        $this->tableCache[$tableName] = $exists;

        return $exists;
    }

    /**
     * Check if a column exists in a table.
     */
    public function columnExists(string $tableName, string $columnName): bool
    {
        if (! $this->tableExists($tableName)) {
            return false;
        }

        if (isset($this->columnCache[$tableName][$columnName])) {
            return $this->columnCache[$tableName][$columnName];
        }

        $this->loadColumnCache($tableName);

        return $this->columnCache[$tableName][$columnName] ?? false;
    }

    /**
     * Check if an index exists on a table.
     */
    public function indexExists(string $tableName, string $indexName): bool
    {
        if (! $this->tableExists($tableName)) {
            return false;
        }

        if (isset($this->indexCache[$tableName][$indexName])) {
            return $this->indexCache[$tableName][$indexName];
        }

        $this->loadConstraintCache($tableName);

        return $this->indexCache[$tableName][$indexName] ?? false;
    }

    /**
     * Check if a constraint (foreign key, unique, etc.) exists on a table.
     */
    public function constraintExists(string $tableName, string $constraintName): bool
    {
        if (! $this->tableExists($tableName)) {
            return false;
        }

        if (isset($this->constraintCache[$tableName][$constraintName])) {
            return $this->constraintCache[$tableName][$constraintName];
        }

        $this->loadConstraintCache($tableName);

        return $this->constraintCache[$tableName][$constraintName] ?? false;
    }

    /**
     * Check if a foreign key exists on a table.
     *
     * This is an alias for constraintExists for clarity.
     */
    public function foreignKeyExists(string $tableName, string $foreignKeyName): bool
    {
        return $this->constraintExists($tableName, $foreignKeyName);
    }

    /**
     * Get all columns for a table.
     *
     * @return array<string, array<string, mixed>> Column name => column details
     */
    public function getColumns(string $tableName): array
    {
        if (! $this->tableExists($tableName)) {
            return [];
        }

        $this->loadColumnCache($tableName);

        return $this->columnDetailsCache[$tableName] ?? [];
    }

    /**
     * Get column details.
     *
     * @return array<string, mixed>|null Column details or null if not found
     */
    public function getColumn(string $tableName, string $columnName): ?array
    {
        $columns = $this->getColumns($tableName);

        return $columns[$columnName] ?? null;
    }

    /**
     * Get all constraints for a table.
     *
     * @return array<ConstraintObject>
     */
    public function getConstraints(string $tableName): array
    {
        if (! $this->tableExists($tableName)) {
            return [];
        }

        return $this->getMetadata()->getConstraints($tableName);
    }

    /**
     * Get all table names in the database.
     *
     * @return array<string>
     */
    public function getTableNames(): array
    {
        return $this->getMetadata()->getTableNames();
    }

    /**
     * Clear all cached data.
     *
     * Call this between migrations to ensure fresh schema state.
     */
    public function clearCache(): void
    {
        $this->tableCache         = [];
        $this->columnCache        = [];
        $this->indexCache         = [];
        $this->constraintCache    = [];
        $this->columnDetailsCache = [];
        $this->metadata           = $this->injectedMetadata;
    }

    /**
     * Get the underlying adapter.
     */
    public function getAdapter(): AdapterInterface
    {
        return $this->adapter;
    }

    private function getMetadata(): MetadataInterface
    {
        if ($this->metadata === null) {
            $this->metadata = $this->createMetadataFromAdapter();
        }

        return $this->metadata;
    }

    /**
     * Create a metadata source from the adapter.
     *
     * Attempts to use PhpDb\Metadata\Source\Factory if available,
     * otherwise falls back to platform-specific metadata source resolution.
     */
    private function createMetadataFromAdapter(): MetadataInterface
    {
        // Try the Factory class first (available in some php-db/phpdb versions)
        if (class_exists('PhpDb\Metadata\Source\Factory')) {
            /** @var MetadataInterface $metadata */
            $metadata = call_user_func(
                ['PhpDb\Metadata\Source\Factory', 'createSourceFromAdapter'],
                $this->adapter,
            );

            return $metadata;
        }

        // Fallback: resolve the platform-specific metadata source directly
        $platformName = $this->adapter->getPlatform()->getName();

        $sourceMap = [
            'MySQL'      => 'PhpDb\Metadata\Source\MysqlMetadata',
            'SQLServer'  => 'PhpDb\Metadata\Source\SqlServerMetadata',
            'SQLite'     => 'PhpDb\Metadata\Source\SqliteMetadata',
            'PostgreSQL' => 'PhpDb\Metadata\Source\PostgresqlMetadata',
            'Oracle'     => 'PhpDb\Metadata\Source\OracleMetadata',
        ];

        $sourceClass = $sourceMap[$platformName] ?? null;

        if ($sourceClass !== null && class_exists($sourceClass)) {
            /** @var MetadataInterface $source */
            $source = new $sourceClass($this->adapter);

            return $source;
        }

        throw new RuntimeException(
            "Unable to create metadata source for platform '{$platformName}'. "
            . 'Provide a MetadataInterface implementation via the SchemaInspector constructor.',
        );
    }

    private function loadColumnCache(string $tableName): void
    {
        if (isset($this->columnCache[$tableName])) {
            return;
        }

        $this->columnCache[$tableName]        = [];
        $this->columnDetailsCache[$tableName] = [];

        $columns = $this->getMetadata()->getColumns($tableName);

        foreach ($columns as $column) {
            $name                                 = $column->getName();
            $this->columnCache[$tableName][$name] = true;

            $this->columnDetailsCache[$tableName][$name] = [
                'name'             => $name,
                'type'             => $column->getDataType(),
                'nullable'         => $column->getIsNullable(),
                'default'          => $column->getColumnDefault(),
                'maxLength'        => $column->getCharacterMaximumLength(),
                'numericPrecision' => $column->getNumericPrecision(),
                'numericScale'     => $column->getNumericScale(),
                'numericUnsigned'  => $column->getNumericUnsigned(),
            ];
        }
    }

    private function loadConstraintCache(string $tableName): void
    {
        if (isset($this->constraintCache[$tableName])) {
            return;
        }

        $this->constraintCache[$tableName] = [];
        $this->indexCache[$tableName]      = [];

        $constraints = $this->getMetadata()->getConstraints($tableName);

        foreach ($constraints as $constraint) {
            $name = $constraint->getName();
            $type = $constraint->getType();

            $this->constraintCache[$tableName][$name] = true;

            // Also track as index for unique constraints and primary keys
            if (in_array($type, ['PRIMARY KEY', 'UNIQUE'], true)) {
                $this->indexCache[$tableName][$name] = true;
            }
        }

        // MySQL stores indexes differently, query them separately
        try {
            $sql    = "SHOW INDEX FROM `{$tableName}`";
            $result = $this->adapter->query($sql, [])->toArray();

            foreach ($result as $row) {
                $indexName                                = $row['Key_name'];
                $this->indexCache[$tableName][$indexName] = true;
            }
        } catch (Exception) {
            // Silently fail - not all adapters support SHOW INDEX
        }
    }
}
