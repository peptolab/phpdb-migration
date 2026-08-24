# phpdb-migration Guide

A complete guide to `peptolab/phpdb-migration`, an idempotent database
migration engine for [php-db/phpdb](https://github.com/php-db/phpdb). For a
quick reference, see the [README](../README.md); this guide goes deeper on
how the package works and how to use it well.

## Contents

- [Introduction](#introduction)
- [Installation](#installation)
- [Quick example](#quick-example)
- [How to write a migration](#how-to-write-a-migration)
- [Helper method reference](#helper-method-reference)
- [How migrations are discovered and run](#how-migrations-are-discovered-and-run)
- [Mismatch strategies](#mismatch-strategies)
- [Dry-run / preview mode](#dry-run--preview-mode)
- [CLI commands](#cli-commands)
- [Laminas/Mezzio integration](#laminasmezzio-integration)
- [Advanced usage](#advanced-usage)
- [Known limitations](#known-limitations)
- [Requirements](#requirements)

## Introduction

Most PHP migration tools give you two options: write raw SQL and hope it
doesn't already exist, or maintain a rigid up()/down() pair per change.
`phpdb-migration` takes a third approach — every operation is **idempotent
by construction**. A migration doesn't say "create this table"; it says
"ensure this table exists, and here's what it should look like." Run it
once, run it a hundred times, run it against a fresh database or one that's
already halfway there — the result is the same.

This works because every `ensure*`/`drop*IfExists` helper on
`AbstractMigration` checks the current schema (via `SchemaInspector`, which
wraps `php-db/phpdb`'s metadata layer with caching) before doing anything.
If the thing you asked for already exists, the operation is skipped — and,
depending on your [mismatch strategy](#mismatch-strategies), the existing
definition can be compared against what you asked for and optionally
corrected.

Applied migrations are tracked in a `migrations` table in the target
database itself (see [How migrations are discovered and
run](#how-migrations-are-discovered-and-run)) — no separate state file or
external store to keep in sync.

## Installation

```bash
composer require peptolab/phpdb-migration
```

You'll also need a `php-db/phpdb` adapter for your database engine, e.g.:

```bash
composer require php-db/phpdb-mysql
```

## Quick example

```php
use PhpDb\Adapter\Adapter;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Migration\MismatchStrategy;

$adapter = new Adapter([
    'driver'   => 'Pdo_Mysql',
    'database' => 'mydb',
    'username' => 'root',
    'password' => '',
]);

$runner = new MigrationRunner(
    adapter: $adapter,
    migrationsPath: __DIR__ . '/data/migrations',
    migrationsNamespace: 'MyApp\\Migrations',
    mismatchStrategy: MismatchStrategy::Report,
);

$runner->ensureMigrationsTable();
$results = $runner->runPending();

foreach ($results as $result) {
    printf("[%s] %s: %s\n", $result['version'], $result['description'], $result['result']->status);
}
```

`MigrationRunner` discovers migration classes in `migrationsPath`, works out
which ones haven't been applied yet (by checking the `migrations` tracking
table), and runs them in version order.

## How to write a migration

A migration is a class extending `AbstractMigration` that implements two
identity methods and one `define()` method describing the desired schema:

```php
<?php

declare(strict_types=1);

namespace MyApp\Migrations;

use PhpDb\Migration\AbstractMigration;
use PhpDb\Sql\Ddl\Column;
use PhpDb\Sql\Ddl\Constraint;
use PhpDb\Sql\Ddl\CreateTable;

class Version20260101000000CreateUsersTable extends AbstractMigration
{
    public function getVersion(): string
    {
        return '20260101000000'; // YYYYMMDDHHMMSS — must match the class's Version prefix
    }

    public function getDescription(): string
    {
        return 'Create users table';
    }

    protected function define(): void
    {
        $this->ensureTable('users', function (CreateTable $table) {
            $id = new Column\Integer('id');
            $id->setOption('unsigned', true);
            $id->setOption('auto_increment', true);
            $table->addColumn($id);

            $table->addColumn(new Column\Varchar('email', 255));
            $table->addColumn(new Column\Varchar('name', 100));

            $createdAt = new Column\Datetime('created_at');
            $createdAt->setDefault('CURRENT_TIMESTAMP');
            $table->addColumn($createdAt);

            $table->addConstraint(new Constraint\PrimaryKey(['id']));
        });

        $this->ensureIndex('users', 'idx_users_email', ['email'], true);
    }
}
```

A few rules `define()` needs to follow:

- **Only call `ensure*`/`drop*`/`executeSql*` helpers** — never build and run
  SQL by hand outside those helpers (see [Helper method
  reference](#helper-method-reference) for the full escape-hatch story).
- **Don't assume execution order across migrations** — each migration only
  sees the schema state left by every migration that ran before it, applied
  in ascending version order.
- **Operations within one `define()` run in the order you write them**, and
  a table created earlier in the same `define()` is immediately visible to
  later `ensureColumn()`/`ensureIndex()` calls in that same method (the
  inspector's cache is updated as you go).

## Helper method reference

All of these are `protected` methods on `AbstractMigration`, called from
your `define()` method. Every `ensure*` and `drop*IfExists` method checks
schema state first and does nothing if the target state is already
satisfied.

### Schema creation

| Method | Description |
|---|---|
| `ensureTable(string $table, callable $callback)` | Create the table if it doesn't exist. The callback receives a `CreateTable` builder — add columns and constraints inside it. If the table already exists, its columns are compared against the callback's definition per the [mismatch strategy](#mismatch-strategies). |
| `ensureColumn(string $table, ColumnInterface $column)` | Add a column if it doesn't exist; compares definitions if it does. |
| `modifyColumn(string $table, string $columnName, Column $column, ?string $newName = null)` | `ALTER`s an existing column to the given definition, optionally renaming it. No-op if the table or column doesn't exist. |
| `ensureIndex(string $table, string $name, array $columns, bool $unique = false)` | Add a (optionally unique) index across `$columns` if it doesn't exist. |
| `ensureUniqueKey(string $table, string $name, array $columns)` | Add a named unique constraint if it doesn't exist. |
| `ensureForeignKey(string $table, string $name, string $column, string $refTable, string $refColumn, string $onDelete = 'RESTRICT', string $onUpdate = 'RESTRICT')` | Add a foreign key if it doesn't exist; compares reference table/column and ON DELETE/UPDATE rules if it does. |
| `ensureCheckConstraint(string $table, string $name, string $expression)` | Add a `CHECK (...)` constraint if it doesn't exist. `$expression` is inserted as raw SQL (e.g. `"status IN ('draft', 'published')"`), so build it from fixed strings, not migration input. For a `CHECK` at table-creation time, add a `Constraint\Check` directly inside `ensureTable()`'s callback instead. |

### Schema removal

| Method | Description |
|---|---|
| `dropTableIfExists(string $table)` | Drop the table if it exists. |
| `dropColumnIfExists(string $table, string $column)` | Drop the column if it exists. |
| `dropIndexIfExists(string $table, string $index)` | Drop the index if it exists. |
| `dropForeignKeyIfExists(string $table, string $constraint)` | Drop the foreign key if it exists. Requires MySQL 8.0.16+ for the `DROP CONSTRAINT` syntax used. |

### Data & raw SQL

| Method | Description |
|---|---|
| `insertRow(string $table, array $data)` | Insert a row (parameterized, not string-built). Intended for seed data. |
| `insertRowIfNotExists(string $table, array $data, array $uniqueColumns)` | Insert only if no row matches `$data`'s values for `$uniqueColumns`. |
| `executeSql(string $sql, ?string $description = null)` | Run arbitrary SQL. This is the escape hatch for anything the DDL builders don't cover (vendor-specific syntax, `ALTER TABLE ... ENGINE=`, etc.) — reach for the `ensure*` methods first. |
| `executeSqlIf(bool $condition, string $sql, ?string $description = null, ?string $skipMessage = null)` | Same as `executeSql()`, but only runs when `$condition` is true; otherwise records `$skipMessage`. |

All operations — including raw `executeSql()` calls — are recorded into the
migration's `MigrationResult` (`executedSql` and `skippedOperations`), which
is what the `db:migrate --dry-run` and `--status` output is built from.

## How migrations are discovered and run

`MigrationRunner::discoverMigrations()` scans `migrationsPath` (non-recursive)
for `.php` files whose basename matches `^Version(\d{14})` — e.g.
`Version20260101000000CreateUsersTable.php`. Each matching file is required,
and the class `{migrationsNamespace}\{filename}` is checked for existence
and for implementing `MigrationInterface`. Discovered migrations are sorted
by `getVersion()` ascending (not by filename or discovery order).

**The 14-digit version returned by `getVersion()` must match the class's
filename prefix.** It's your responsibility to keep the two in sync — the
`db:migrate:create` CLI command below generates both together correctly.

A `migrations` table is created automatically (`ensureMigrationsTable()`,
idempotent) with columns `id`, `version` (unique), `description`, and
`executed_at`. `MigrationRunner::runPending()`/`runMigration()` check this
table to skip anything already applied, and insert a row once a migration
completes successfully (or is itself skipped internally by its own `define()`
logic). This is the same pattern used by essentially every migration tool
(Rails, Doctrine, Flyway, Laravel) — the tracking state lives in the same
database as the schema it describes, so every environment's history stays
self-contained.

## Mismatch strategies

When an `ensure*` method finds the thing it was asked to create already
exists, the configured `MismatchStrategy` decides what happens next:

| Strategy | Behavior |
|---|---|
| `MismatchStrategy::Ignore` | Skip silently — don't even compare definitions. |
| `MismatchStrategy::Report` (default) | Compare the existing definition against the one you asked for; record any differences in `MigrationResult::$mismatches`, but don't change anything. |
| `MismatchStrategy::Alter` | Same comparison as `Report`, but auto-`ALTER`s the schema to match your definition when a mismatch is found. |

A mismatch entry looks like:

```php
['table' => 'users', 'column' => 'email', 'field' => 'length', 'expected' => '320', 'actual' => '255']
```

Set the strategy on the `MigrationRunner` constructor, or per-run via the
CLI's `--resolution-strategy` option. It's applied uniformly across every
migration in a run — you can't mix strategies per migration.

`MismatchStrategy::Alter` is powerful but blunt: it will alter production
schema automatically based on what your migration code says it should look
like. Use `Report` in normal operation and reach for `Alter` deliberately
(e.g. a one-off reconciliation run), not as your default.

## Dry-run / preview mode

Call `preview()` instead of `up()` (or use `db:migrate --dry-run`) to get
the list of SQL statements a migration *would* run, without executing
anything or touching the database:

```php
$sqlPreview = $migration->preview($adapter, $inspector);
```

Preview mode still checks real schema state (so `ensureTable()` on an
existing table correctly reports "nothing to do"), it just never calls
`$adapter->query()`/`executeQuery()` for anything other than the read-only
inspection itself.

## CLI commands

Registered via `ConfigProvider` for `laminas-cli`/Mezzio applications.

### `db:migrate`

```bash
vendor/bin/laminas db:migrate --status                              # show status table
vendor/bin/laminas db:migrate --dry-run                              # preview SQL, no execution
vendor/bin/laminas db:migrate --force                                # run without confirmation prompt
vendor/bin/laminas db:migrate --force --resolution-strategy=alter    # override the configured strategy
```

| Option | Description |
|---|---|
| `--status, -s` | Show a table of every discovered migration and whether it's applied. |
| `--dry-run` | Preview SQL for pending migrations without executing. |
| `--force, -f` | Skip the interactive confirmation prompt (use in CI/CD). |
| `--resolution-strategy, -r` | Override the configured mismatch strategy for this run: `ignore`, `report`, or `alter`. |

Without `--force`, `db:migrate` lists the pending migrations and asks for
confirmation before running them.

### `db:migrate:create`

```bash
vendor/bin/laminas db:migrate:create "Add tags table"
```

Creates `Version{timestamp}_{PascalCaseDescription}.php` in the configured
migrations directory (creating the directory if it doesn't exist yet), with
commented-out examples of each `ensure*` helper to get you started.

## Laminas/Mezzio integration

The package auto-registers via `ConfigProvider` (wired through
`composer.json`'s `extra.laminas.config-provider`). Configure it under the
`phpdb-migration` config key:

```php
// config/autoload/migrations.global.php
use PhpDb\Migration\MismatchStrategy;

return [
    'phpdb-migration' => [
        'migrations_path'      => getcwd() . '/data/migrations',
        'migrations_namespace' => 'Data\\Migration',
        'adapter_service'      => \PhpDb\Adapter\AdapterInterface::class,
        'resolution'           => MismatchStrategy::Report,
    ],
];
```

| Key | Type | Default | Description |
|---|---|---|---|
| `migrations_path` | `string` | `getcwd() . '/data/migrations'` | Directory scanned for migration files. |
| `migrations_namespace` | `string` | `App\Migration` | PSR-4 namespace migration classes are required/resolved under. |
| `adapter_service` | `string` | `PhpDb\Adapter\AdapterInterface::class` | Container service name resolved as the `AdapterInterface` to migrate. |
| `resolution` | `MismatchStrategy\|string` | `MismatchStrategy::Report` | Default mismatch strategy; a string is accepted and converted via `MismatchStrategy::from()`. |

`MigrationRunnerFactory` also pulls a `MetadataInterface` from the container
if one is registered, and passes it through to `SchemaInspector` — useful if
your app already has a metadata source configured that you want reused
instead of one being created fresh per inspector.

## Advanced usage

### Running migrations outside a framework

`MigrationRunner` has no framework dependency — construct it directly (as in
the [quick example](#quick-example)) for scripts, workers, or test bootstrap
code. Common calls:

```php
$runner->getStatus();            // array of {version, description, status, executed_at}
$runner->getPendingMigrations(); // array<MigrationInterface> not yet applied
$runner->previewPending();       // array of {version, description, sql: array<string>}
$runner->runPending();           // runs everything pending, returns array of {version, description, result}
$runner->runMigration($m);       // run one specific migration
```

### Writing idempotent operations the helpers don't cover

If you need something outside the `ensure*` set (e.g. a data backfill, a
vendor-specific `ALTER`), use `executeSqlIf()` with an explicit condition
computed from `SchemaInspector`/a query, rather than reaching for `Ignore`
mismatch strategy or skipping the idempotency check entirely:

```php
protected function define(): void
{
    $needsBackfill = ! $this->inspector->columnExists('users', 'legacy_id')
        ? false
        : /* your own condition */ true;

    $this->executeSqlIf(
        $needsBackfill,
        'UPDATE users SET legacy_id = id WHERE legacy_id IS NULL',
        description: 'Backfill legacy_id',
        skipMessage: 'legacy_id already backfilled',
    );
}
```

### Seed data

```php
$this->insertRowIfNotExists(
    'roles',
    ['name' => 'admin', 'permissions' => 'all'],
    uniqueColumns: ['name'],
);
```

### Testing your own migrations

Nothing in this package is specific to a test framework, but the pattern
used in this repo's own test suite (`test/integration/`) works well: build a
throwaway schema in `setUp()`, instantiate the migration directly (or an
anonymous class extending `AbstractMigration`), call `up($adapter,
$inspector)`, and assert against `SchemaInspector` (after
`$inspector->clearCache()`) rather than against `MigrationResult` alone —
that way you're asserting the actual resulting schema, not just that the
migration claims success.

## Known limitations

- **No locking around concurrent runs.** `MigrationRunner::runPending()`
  doesn't take any advisory lock. If two processes call it concurrently
  against the same database (e.g. two app instances migrating on deploy),
  they can both pick up the same pending migration and race — the
  `migrations.version` unique key will reject a double-insert, but as a raw
  SQL error rather than a graceful skip. Ensure only one process runs
  migrations at a time (e.g. a deploy-time migration step run from a single
  host), or serialize your own call to `runPending()` with an external lock.
- **`ensureCheckConstraint()`'s expression is not parameterized.** Like all
  DDL, `CHECK` expressions are embedded directly in the generated SQL —
  build them from fixed migration-authored strings, never from user input.

## Requirements

- PHP 8.3+
- `php-db/phpdb` ^0.6.0, plus a matching database adapter (e.g.
  `php-db/phpdb-mysql`)
- MySQL 8.0.16+ if you use `dropForeignKeyIfExists()` (requires
  `DROP CONSTRAINT` support)
- `symfony/console` ^6.0 || ^7.0 for the CLI commands
- `laminas/laminas-servicemanager` and `laminas/laminas-cli` (optional) for
  the Laminas/Mezzio integration
