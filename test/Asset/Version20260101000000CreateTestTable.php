<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Asset;

use PhpDb\Migration\AbstractMigration;
use PhpDb\Sql\Ddl\Column;
use PhpDb\Sql\Ddl\Constraint;
use PhpDb\Sql\Ddl\CreateTable;

class Version20260101000000CreateTestTable extends AbstractMigration
{
    public function getVersion(): string
    {
        return '20260101000000';
    }

    public function getDescription(): string
    {
        return 'Create test table';
    }

    protected function define(): void
    {
        $this->ensureTable('test_table', function (CreateTable $table) {
            $id = new Column\Integer('id');
            $id->setOption('unsigned', true);
            $id->setOption('auto_increment', true);
            $table->addColumn($id);

            $table->addColumn(new Column\Varchar('name', 255));

            $table->addConstraint(new Constraint\PrimaryKey(['id']));
        });
    }
}
