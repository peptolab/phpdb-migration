<?php

declare(strict_types=1);

namespace PhpDb\Migration\Command;

use Psr\Container\ContainerInterface;

use function getcwd;

class DbMigrateCreateCommandFactory
{
    public function __invoke(ContainerInterface $container): DbMigrateCreateCommand
    {
        $config = $container->get('config')['phpdb-migration'] ?? [];

        $migrationsPath      = $config['migrations_path'] ?? getcwd() . '/data/migrations';
        $migrationsNamespace = $config['migrations_namespace'] ?? 'App\\Migration';

        return new DbMigrateCreateCommand($migrationsPath, $migrationsNamespace);
    }
}
