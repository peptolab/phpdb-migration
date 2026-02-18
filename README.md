# phpdb-migration

Idempotent database migration engine for [php-db/phpdb](https://github.com/php-db/phpdb).

## Features

- **Idempotent migrations** - Safe to run multiple times; operations check schema state before executing
- **Definition mismatch detection** - Compare existing schema against desired definitions with configurable strategies (ignore, report, alter)
- **Rich helper methods** - `ensureTable()`, `ensureColumn()`, `ensureIndex()`, `ensureForeignKey()`, `ensureUniqueKey()`, and more
- **Dry-run preview** - See what SQL would execute without making changes
- **CLI commands** - `db:migrate` and `db:migrate:create` via Symfony Console
- **Laminas/Mezzio integration** - ConfigProvider for container-based setup with laminas-cli

## Installation

```bash
composer require peptolab/phpdb-migration
```

## Quick Start

### Standalone Usage

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
```

### Laminas/Mezzio Integration

The package auto-registers via the `ConfigProvider`. Add your configuration:

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

## Configuration

| Key                    | Type                    | Default                             | Description                              |
|------------------------|-------------------------|-------------------------------------|------------------------------------------|
| `migrations_path`      | `string`                | `getcwd() . '/data/migrations'`    | Directory containing migration files     |
| `migrations_namespace` | `string`                | `App\Migration`                     | PSR-4 namespace of migration classes     |
| `adapter_service`      | `string`                | `PhpDb\Adapter\AdapterInterface`    | Container service name for the adapter   |
| `resolution`           | `MismatchStrategy\|string` | `MismatchStrategy::Report`       | How to handle definition mismatches      |

## Writing Migrations

Create a migration class that extends `AbstractMigration`:

```php
<?php

declare(strict_types=1);

namespace Data\Migration;

use PhpDb\Migration\AbstractMigration;
use PhpDb\Sql\Ddl\Column;
use PhpDb\Sql\Ddl\Constraint;
use PhpDb\Sql\Ddl\CreateTable;

class Version20260101000000CreateUsersTable extends AbstractMigration
{
    public function getVersion(): string
    {
        return '20260101000000';
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

            $table->addConstraint(new Constraint\PrimaryKey(['id']));
        });

        $this->ensureIndex('users', 'idx_users_email', ['email'], true);
    }
}
```

## Available Helper Methods

### Schema Creation
- `ensureTable(string $table, callable $callback)` - Create table if not exists; validates definition if exists
- `ensureColumn(string $table, ColumnInterface $column)` - Add column if not exists; validates if exists
- `ensureIndex(string $table, string $name, array $columns, bool $unique = false)` - Add index if not exists
- `ensureUniqueKey(string $table, string $name, array $columns)` - Add unique constraint if not exists
- `ensureForeignKey(string $table, string $name, string $col, string $refTable, string $refCol, string $onDelete, string $onUpdate)` - Add FK if not exists

### Schema Removal
- `dropTableIfExists(string $table)`
- `dropColumnIfExists(string $table, string $column)`
- `dropIndexIfExists(string $table, string $index)`
- `dropForeignKeyIfExists(string $table, string $constraint)`

### Data & Raw SQL
- `executeSql(string $sql, ?string $description)` - Execute raw SQL
- `executeSqlIf(bool $condition, string $sql, ?string $description, ?string $skipMessage)` - Conditional SQL
- `modifyColumn(string $table, string $column, string $definition, ?string $newName)` - ALTER column
- `insertRow(string $table, array $data)` - Insert a row
- `insertRowIfNotExists(string $table, array $data, array $uniqueColumns)` - Conditional insert

## Mismatch Strategy

When an `ensure*` method finds an existing schema object, the mismatch strategy determines what happens:

| Strategy              | Behavior                                                       |
|-----------------------|----------------------------------------------------------------|
| `MismatchStrategy::Ignore` | Skip silently (original behavior)                         |
| `MismatchStrategy::Report` | Log mismatch details in `MigrationResult::$mismatches`    |
| `MismatchStrategy::Alter`  | Auto-ALTER the schema to match the desired definition     |

Mismatches are tracked per migration and include: table name, column/constraint name, field that differs, expected value, and actual value.

## CLI Commands

### `db:migrate`

Run pending database migrations.

```bash
# Show migration status
vendor/bin/laminas db:migrate --status

# Preview SQL (dry run)
vendor/bin/laminas db:migrate --dry-run

# Run migrations
vendor/bin/laminas db:migrate --force

# Run with specific resolution strategy
vendor/bin/laminas db:migrate --force --resolution-strategy=alter
```

Options:
- `--status, -s` - Show migration status
- `--dry-run` - Preview SQL without executing
- `--force, -f` - Skip confirmation prompt
- `--resolution-strategy, -r` - Override mismatch strategy (`ignore`, `report`, `alter`)

### `db:migrate:create`

Create a new migration file.

```bash
vendor/bin/laminas db:migrate:create "Add tags table"
```

This generates a timestamped migration file in the configured migrations directory.

## Examples

See the [docs/examples](docs/examples/) directory for complete migration examples.

## License

BSD-3-Clause. See [LICENSE](LICENSE).
