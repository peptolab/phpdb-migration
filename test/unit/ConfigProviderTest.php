<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Migration\Command\DbMigrateCommand;
use PhpDb\Migration\Command\DbMigrateCommandFactory;
use PhpDb\Migration\Command\DbMigrateCreateCommand;
use PhpDb\Migration\Command\DbMigrateCreateCommandFactory;
use PhpDb\Migration\ConfigProvider;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Migration\MigrationRunnerFactory;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    private ConfigProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ConfigProvider();
    }

    public function testInvokeReturnsArray(): void
    {
        $config = ($this->provider)();

        self::assertIsArray($config);
        self::assertArrayHasKey('dependencies', $config);
        self::assertArrayHasKey('laminas-cli', $config);
    }

    public function testDependenciesContainFactories(): void
    {
        $deps = $this->provider->getDependencies();

        self::assertArrayHasKey('factories', $deps);

        $factories = $deps['factories'];

        self::assertArrayHasKey(MigrationRunner::class, $factories);
        self::assertSame(MigrationRunnerFactory::class, $factories[MigrationRunner::class]);

        self::assertArrayHasKey(DbMigrateCommand::class, $factories);
        self::assertSame(DbMigrateCommandFactory::class, $factories[DbMigrateCommand::class]);

        self::assertArrayHasKey(DbMigrateCreateCommand::class, $factories);
        self::assertSame(DbMigrateCreateCommandFactory::class, $factories[DbMigrateCreateCommand::class]);
    }

    public function testCliConfigContainsCommands(): void
    {
        $cliConfig = $this->provider->getCliConfig();

        self::assertArrayHasKey('commands', $cliConfig);

        $commands = $cliConfig['commands'];

        self::assertArrayHasKey('db:migrate', $commands);
        self::assertSame(DbMigrateCommand::class, $commands['db:migrate']);

        self::assertArrayHasKey('db:migrate:create', $commands);
        self::assertSame(DbMigrateCreateCommand::class, $commands['db:migrate:create']);
    }
}
