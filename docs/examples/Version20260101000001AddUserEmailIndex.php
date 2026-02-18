<?php

declare(strict_types=1);

namespace App\Migration;

use PhpDb\Migration\AbstractMigration;
use PhpDb\Sql\Ddl\Column;

/**
 * Example migration: Add columns and indexes to an existing table.
 */
class Version20260101000001AddUserEmailIndex extends AbstractMigration
{
    public function getVersion(): string
    {
        return '20260101000001';
    }

    public function getDescription(): string
    {
        return 'Add user verification columns and index';
    }

    protected function define(): void
    {
        // Add a new column
        $verified = new Column\Boolean('is_verified');
        $verified->setDefault(0);
        $this->ensureColumn('users', $verified);

        // Add a nullable column
        $verifiedAt = new Column\Datetime('verified_at');
        $verifiedAt->setNullable(true);
        $this->ensureColumn('users', $verifiedAt);

        // Add an index on the new column
        $this->ensureIndex('users', 'idx_users_verified', ['is_verified']);

        // Add a foreign key to another table
        $this->ensureForeignKey(
            'posts',
            'fk_posts_author',
            'author_id',
            'users',
            'id',
            'CASCADE',
            'CASCADE',
        );
    }
}
