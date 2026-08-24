<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Command;

use PhpDb\Migration\Command\DbMigrateCreateCommand;
use PhpDb\Migration\Command\DbMigrateCreateCommandFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class DbMigrateCreateCommandFactoryTest extends TestCase
{
    public function testInvokeUsesConfiguredPathAndNamespace(): void
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

        self::assertInstanceOf(DbMigrateCreateCommand::class, $command);
    }

    public function testInvokeUsesDefaultsWhenConfigIsMissing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('config')
            ->willReturn([]);

        $factory = new DbMigrateCreateCommandFactory();
        $command = $factory($container);

        self::assertInstanceOf(DbMigrateCreateCommand::class, $command);
    }
}
