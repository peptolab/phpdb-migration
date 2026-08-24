<?php

declare(strict_types=1);

namespace PhpDb\Migration\Command;

use PhpDb\Migration\MigrationRunner;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class DbMigrateCommandFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): DbMigrateCommand
    {
        $runner = $container->get(MigrationRunner::class);

        return new DbMigrateCommand($runner);
    }
}
