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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigProviderTest extends TestCase
{
    private ConfigProvider $provider;

    #[Test]
    public function cliConfigContainsCommands(): void
    {
        $cliConfig = $this->provider->getCliConfig();

        static::assertArrayHasKey('commands', $cliConfig);

        $commands = $cliConfig['commands'];

        static::assertArrayHasKey('db:migrate', $commands);
        static::assertSame(DbMigrateCommand::class, $commands['db:migrate']);

        static::assertArrayHasKey('db:migrate:create', $commands);
        static::assertSame(DbMigrateCreateCommand::class, $commands['db:migrate:create']);
    }

    #[Test]
    public function dependenciesContainFactories(): void
    {
        $deps = $this->provider->getDependencies();

        static::assertArrayHasKey('factories', $deps);

        $factories = $deps['factories'];

        static::assertArrayHasKey(MigrationRunner::class, $factories);
        static::assertSame(MigrationRunnerFactory::class, $factories[MigrationRunner::class]);

        static::assertArrayHasKey(DbMigrateCommand::class, $factories);
        static::assertSame(DbMigrateCommandFactory::class, $factories[DbMigrateCommand::class]);

        static::assertArrayHasKey(DbMigrateCreateCommand::class, $factories);
        static::assertSame(DbMigrateCreateCommandFactory::class, $factories[DbMigrateCreateCommand::class]);
    }

    #[Test]
    public function invokeReturnsArray(): void
    {
        $config = ($this->provider)();

        static::assertIsArray($config);
        static::assertArrayHasKey('dependencies', $config);
        static::assertArrayHasKey('laminas-cli', $config);
    }

    protected function setUp(): void
    {
        $this->provider = new ConfigProvider();
    }
}
