<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Command;

use PhpDb\Migration\Command\DbMigrateCommand;
use PhpDb\Migration\Command\DbMigrateCommandFactory;
use PhpDb\Migration\MigrationRunner;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class DbMigrateCommandFactoryTest extends TestCase
{
    public function testInvokeBuildsCommandFromContainer(): void
    {
        $runner = $this->createMock(MigrationRunner::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())
            ->method('get')
            ->with(MigrationRunner::class)
            ->willReturn($runner);

        $factory = new DbMigrateCommandFactory();
        $command = $factory($container);

        self::assertInstanceOf(DbMigrateCommand::class, $command);
    }
}
