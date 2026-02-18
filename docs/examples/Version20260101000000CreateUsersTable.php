<?php

declare(strict_types=1);

namespace App\Migration;

use PhpDb\Migration\AbstractMigration;
use PhpDb\Sql\Ddl\Column;
use PhpDb\Sql\Ddl\Constraint;
use PhpDb\Sql\Ddl\CreateTable;

/**
 * Example migration: Create a users table with common columns.
 */
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
            // Primary key
            $id = new Column\Integer('id');
            $id->setOption('unsigned', true);
            $id->setOption('auto_increment', true);
            $table->addColumn($id);

            // User fields
            $table->addColumn(new Column\Varchar('email', 255));
            $table->addColumn(new Column\Varchar('name', 100));
            $table->addColumn(new Column\Varchar('password_hash', 255));

            // Nullable bio
            $bio = new Column\Text('bio');
            $bio->setNullable(true);
            $table->addColumn($bio);

            // Timestamps
            $createdAt = new Column\Datetime('created_at');
            $createdAt->setDefault('CURRENT_TIMESTAMP');
            $table->addColumn($createdAt);

            $updatedAt = new Column\Datetime('updated_at');
            $updatedAt->setNullable(true);
            $table->addColumn($updatedAt);

            // Constraints
            $table->addConstraint(new Constraint\PrimaryKey(['id']));
        });

        // Add unique index on email
        $this->ensureIndex('users', 'idx_users_email', ['email'], true);
    }
}
