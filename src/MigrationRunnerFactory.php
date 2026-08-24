<?php

declare(strict_types=1);

namespace PhpDb\Migration;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Metadata\MetadataInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

use function getcwd;
use function is_string;

class MigrationRunnerFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __invoke(ContainerInterface $container): MigrationRunner
    {
        $config = $container->get('config')['phpdb-migration'] ?? [];

        $adapterServiceConfig = $config['adapter_service'] ?? null;
        $adapterService       = is_string($adapterServiceConfig) ? $adapterServiceConfig : AdapterInterface::class;

        /** @var AdapterInterface $adapter */
        $adapter = $container->get($adapterService);

        $migrationsPathConfig = $config['migrations_path'] ?? null;
        $migrationsPath       = is_string($migrationsPathConfig)
            ? $migrationsPathConfig
            : (string) getcwd() . '/data/migrations';

        $migrationsNamespaceConfig = $config['migrations_namespace'] ?? null;
        $migrationsNamespace       = is_string($migrationsNamespaceConfig)
            ? $migrationsNamespaceConfig
            : 'App\\Migration';

        $resolutionConfig = $config['resolution'] ?? null;
        $resolution       = match (true) {
            $resolutionConfig instanceof MismatchStrategy => $resolutionConfig,
            is_string($resolutionConfig) => MismatchStrategy::from($resolutionConfig),
            default                      => MismatchStrategy::Report,
        };

        $metadataRaw = $container->has(MetadataInterface::class)
            ? $container->get(MetadataInterface::class)
            : null;
        $metadata = $metadataRaw instanceof MetadataInterface ? $metadataRaw : null;

        return new MigrationRunner(
            $adapter,
            $migrationsPath,
            $migrationsNamespace,
            $resolution,
            $metadata,
        );
    }
}
