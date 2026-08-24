<?php

declare(strict_types=1);

namespace PhpDb\Migration\Command;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function getcwd;
use function is_string;

class DbMigrateCreateCommandFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): DbMigrateCreateCommand
    {
        $config = $container->get('config')['phpdb-migration'] ?? [];

        $migrationsPathConfig = $config['migrations_path'] ?? null;
        $migrationsPath       = is_string($migrationsPathConfig)
            ? $migrationsPathConfig
            : (string) getcwd() . '/data/migrations';

        $migrationsNamespaceConfig = $config['migrations_namespace'] ?? null;
        $migrationsNamespace       = is_string($migrationsNamespaceConfig)
            ? $migrationsNamespaceConfig
            : 'App\\Migration';

        return new DbMigrateCreateCommand($migrationsPath, $migrationsNamespace);
    }
}
