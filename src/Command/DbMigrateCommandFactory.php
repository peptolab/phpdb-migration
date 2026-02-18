<?php

declare(strict_types=1);

namespace PhpDb\Migration\Command;

use PhpDb\Migration\MigrationRunner;
use Psr\Container\ContainerInterface;

class DbMigrateCommandFactory
{
    public function __invoke(ContainerInterface $container): DbMigrateCommand
    {
        /** @var MigrationRunner $runner */
        $runner = $container->get(MigrationRunner::class);

        return new DbMigrateCommand($runner);
    }
}
