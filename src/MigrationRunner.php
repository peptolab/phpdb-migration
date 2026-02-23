<?php

declare(strict_types=1);

namespace PhpDb\Migration;

use DirectoryIterator;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Sql\Ddl\Column;
use PhpDb\Sql\Ddl\Constraint;
use PhpDb\Sql\Ddl\CreateTable;
use PhpDb\Sql\Sql;

use function array_filter;
use function array_map;
use function class_exists;
use function in_array;
use function is_dir;
use function is_subclass_of;
use function preg_match;
use function sprintf;
use function str_replace;
use function usort;

/**
 * Orchestrates migration discovery and execution.
 */
class MigrationRunner
{
    private const MIGRATIONS_TABLE = 'migrations';

    private SchemaInspector $inspector;

    /** @var array<MigrationInterface> */
    private array $discoveredMigrations = [];

    public function __construct(
        private readonly AdapterInterface $adapter,
        private readonly string $migrationsPath,
        private readonly string $migrationsNamespace = 'App\\Migration',
        private readonly MismatchStrategy $mismatchStrategy = MismatchStrategy::Report,
        ?MetadataInterface $metadata = null,
    ) {
        $this->inspector = new SchemaInspector($adapter, $metadata);
    }

    /**
     * Ensure the migrations tracking table exists.
     */
    public function ensureMigrationsTable(): void
    {
        if ($this->inspector->tableExists(self::MIGRATIONS_TABLE)) {
            return;
        }

        $table = new CreateTable(self::MIGRATIONS_TABLE);

        $id = new Column\Integer('id');
        $id->setOption('unsigned', true);
        $id->setOption('auto_increment', true);
        $table->addColumn($id);

        $version = new Column\Varchar('version', 14);
        $table->addColumn($version);

        $description = new Column\Varchar('description', 255);
        $table->addColumn($description);

        $executedAt = new Column\Datetime('executed_at');
        $table->addColumn($executedAt);

        $table->addConstraint(new Constraint\PrimaryKey(['id']));
        $table->addConstraint(new Constraint\UniqueKey(['version'], 'uk_migrations_version'));

        $sql       = new Sql($this->adapter);
        $sqlString = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $sql->buildSqlString($table));

        $this->adapter->query($sqlString, []);
        $this->inspector->clearCache();
    }

    /**
     * Discover all migration classes from the migrations directory.
     *
     * @return array<MigrationInterface>
     */
    public function discoverMigrations(): array
    {
        if (! empty($this->discoveredMigrations)) {
            return $this->discoveredMigrations;
        }

        $migrations = [];

        if (! is_dir($this->migrationsPath)) {
            return $migrations;
        }

        $iterator = new DirectoryIterator($this->migrationsPath);

        foreach ($iterator as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getBasename('.php');

            // Expected pattern: Version{timestamp}{description} or Version{timestamp}_{description}
            if (! preg_match('/^Version(\d{14})/', $filename)) {
                continue;
            }

            $className = $this->migrationsNamespace . '\\' . $filename;

            // Include the file if class doesn't exist yet
            if (! class_exists($className)) {
                require_once $file->getPathname();
            }

            if (! class_exists($className)) {
                continue;
            }

            if (! is_subclass_of($className, MigrationInterface::class)) {
                continue;
            }

            /** @var MigrationInterface $migration */
            $migration    = new $className();
            $migrations[] = $migration;
        }

        // Sort by version (timestamp)
        usort($migrations, fn (MigrationInterface $a, MigrationInterface $b) =>
            $a->getVersion() <=> $b->getVersion());

        $this->discoveredMigrations = $migrations;

        return $migrations;
    }

    /**
     * Get versions that have already been applied.
     *
     * @return array<string>
     */
    public function getAppliedVersions(): array
    {
        $this->ensureMigrationsTable();

        $sql    = sprintf('SELECT version FROM `%s` ORDER BY version', self::MIGRATIONS_TABLE);
        $result = $this->adapter->query($sql, [])->toArray();

        return array_map(fn ($row) => $row['version'], $result);
    }

    /**
     * Get migrations that have not yet been applied.
     *
     * @return array<MigrationInterface>
     */
    public function getPendingMigrations(): array
    {
        $all     = $this->discoverMigrations();
        $applied = $this->getAppliedVersions();

        return array_filter($all, fn (MigrationInterface $m) =>
            ! in_array($m->getVersion(), $applied, true));
    }

    /**
     * Get migration status for all discovered migrations.
     *
     * @return array<array{version: string, description: string, status: string, executed_at: string|null}>
     */
    public function getStatus(): array
    {
        $migrations = $this->discoverMigrations();
        $applied    = $this->getAppliedMigrationDetails();

        $status = [];

        foreach ($migrations as $migration) {
            $version     = $migration->getVersion();
            $appliedInfo = $applied[$version] ?? null;

            $status[] = [
                'version'     => $version,
                'description' => $migration->getDescription(),
                'status'      => $appliedInfo !== null ? 'applied' : 'pending',
                'executed_at' => $appliedInfo['executed_at'] ?? null,
            ];
        }

        return $status;
    }

    /**
     * Run all pending migrations.
     *
     * @return array<array{version: string, description: string, result: MigrationResult}>
     */
    public function runPending(): array
    {
        $pending = $this->getPendingMigrations();
        $results = [];

        foreach ($pending as $migration) {
            $results[] = $this->runMigration($migration);

            // Clear inspector cache between migrations
            $this->inspector->clearCache();
        }

        return $results;
    }

    /**
     * Run a specific migration.
     *
     * @return array{version: string, description: string, result: MigrationResult}
     */
    public function runMigration(MigrationInterface $migration): array
    {
        $version     = $migration->getVersion();
        $description = $migration->getDescription();

        // Check if already applied
        if (in_array($version, $this->getAppliedVersions(), true)) {
            return [
                'version'     => $version,
                'description' => $description,
                'result'      => MigrationResult::skipped(['Migration already applied']),
            ];
        }

        // Set mismatch strategy if the migration supports it
        if ($migration instanceof AbstractMigration) {
            $migration->setMismatchStrategy($this->mismatchStrategy);
        }

        $result = $migration->up($this->adapter, $this->inspector);

        // Record the migration if successful
        if ($result->isSuccess() || $result->isSkipped()) {
            $this->recordMigration($version, $description);
        }

        return [
            'version'     => $version,
            'description' => $description,
            'result'      => $result,
        ];
    }

    /**
     * Preview SQL for pending migrations without executing.
     *
     * @return array<array{version: string, description: string, sql: array<string>}>
     */
    public function previewPending(): array
    {
        $pending  = $this->getPendingMigrations();
        $previews = [];

        foreach ($pending as $migration) {
            $previews[] = [
                'version'     => $migration->getVersion(),
                'description' => $migration->getDescription(),
                'sql'         => $migration->preview($this->adapter, $this->inspector),
            ];
        }

        return $previews;
    }

    /**
     * Get the schema inspector instance.
     */
    public function getInspector(): SchemaInspector
    {
        return $this->inspector;
    }

    /**
     * Get the configured mismatch strategy.
     */
    public function getMismatchStrategy(): MismatchStrategy
    {
        return $this->mismatchStrategy;
    }

    /**
     * Record a migration as applied.
     */
    private function recordMigration(string $version, string $description): void
    {
        $sql = sprintf(
            'INSERT INTO `%s` (`version`, `description`, `executed_at`) VALUES (?, ?, NOW())',
            self::MIGRATIONS_TABLE,
        );

        $this->adapter->query($sql, [$version, $description]);
    }

    /**
     * Get details of applied migrations.
     *
     * @return array<string, array{version: string, description: string, executed_at: string}>
     */
    private function getAppliedMigrationDetails(): array
    {
        $this->ensureMigrationsTable();

        $sql    = sprintf('SELECT version, description, executed_at FROM `%s`', self::MIGRATIONS_TABLE);
        $result = $this->adapter->query($sql, [])->toArray();

        $details = [];
        foreach ($result as $row) {
            $details[$row['version']] = [
                'version'     => $row['version'],
                'description' => $row['description'],
                'executed_at' => $row['executed_at'],
            ];
        }

        return $details;
    }
}
