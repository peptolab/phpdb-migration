<?php

declare(strict_types=1);

namespace PhpDb\Migration;

use PhpDb\Adapter\AdapterInterface;

/**
 * Contract for all database migrations.
 *
 * Migrations are idempotent operations that modify the database schema.
 * Each migration must be able to determine what needs to be applied vs skipped,
 * working safely on both fresh installs and existing databases.
 */
interface MigrationInterface
{
    /**
     * Get the migration version (14-digit timestamp: YYYYMMDDHHMMSS).
     *
     * This version is used for ordering migrations and tracking which have been applied.
     */
    public function getVersion(): string;

    /**
     * Get a human-readable description of this migration.
     */
    public function getDescription(): string;

    /**
     * Execute the migration.
     *
     * This method should be idempotent - calling it multiple times should have the
     * same effect as calling it once. Use the SchemaInspector to check existing
     * schema state before making changes.
     */
    public function up(AdapterInterface $adapter, SchemaInspector $inspector): MigrationResult;

    /**
     * Preview the SQL that would be executed without making any changes.
     *
     * @return array<string> List of SQL statements that would be executed
     */
    public function preview(AdapterInterface $adapter, SchemaInspector $inspector): array;
}
