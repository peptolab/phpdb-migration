<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Command;

use PhpDb\Migration\Command\DbMigrateCreateCommand;
use PhpDb\Migration\Command\DbMigrateCreateCommandFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class DbMigrateCreateCommandFactoryTest extends TestCase
{
    #[Test]
    public function invokeUsesConfiguredPathAndNamespace(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('config')
            ->willReturn(['phpdb-migration' => [
                'migrations_path'      => '/tmp/custom-migrations',
                'migrations_namespace' => 'App\\Custom',
            ]]);

        $factory = new DbMigrateCreateCommandFactory();
        $command = $factory($container);

        static::assertInstanceOf(DbMigrateCreateCommand::class, $command);
    }

    #[Test]
    public function invokeUsesDefaultsWhenConfigIsMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('config')
            ->willReturn([]);

        $factory = new DbMigrateCreateCommandFactory();
        $command = $factory($container);

        static::assertInstanceOf(DbMigrateCreateCommand::class, $command);
    }
}
