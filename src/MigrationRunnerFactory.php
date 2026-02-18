<?php

declare(strict_types=1);

namespace PhpDb\Migration;

use PhpDb\Adapter\AdapterInterface;
use Psr\Container\ContainerInterface;

use function getcwd;
use function is_string;

class MigrationRunnerFactory
{
    public function __invoke(ContainerInterface $container): MigrationRunner
    {
        $config = $container->get('config')['phpdb-migration'] ?? [];

        $adapterService = $config['adapter_service'] ?? AdapterInterface::class;

        /** @var AdapterInterface $adapter */
        $adapter = $container->get(is_string($adapterService) ? $adapterService : AdapterInterface::class);

        $migrationsPath      = $config['migrations_path'] ?? getcwd() . '/data/migrations';
        $migrationsNamespace = $config['migrations_namespace'] ?? 'App\\Migration';

        $resolution = $config['resolution'] ?? MismatchStrategy::Report;

        if (is_string($resolution)) {
            $resolution = MismatchStrategy::from($resolution);
        }

        return new MigrationRunner(
            $adapter,
            $migrationsPath,
            $migrationsNamespace,
            $resolution,
        );
    }
}
