<?php

declare(strict_types=1);

namespace PhpDb\Migration;

use PhpDb\Migration\Command\DbMigrateCommand;
use PhpDb\Migration\Command\DbMigrateCommandFactory;
use PhpDb\Migration\Command\DbMigrateCreateCommand;
use PhpDb\Migration\Command\DbMigrateCreateCommandFactory;

class ConfigProvider
{
    /** @return array<string, mixed> */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'laminas-cli'  => $this->getCliConfig(),
        ];
    }

    /** @return array<string, array<string, string>> */
    public function getDependencies(): array
    {
        return [
            'factories' => [
                MigrationRunner::class        => MigrationRunnerFactory::class,
                DbMigrateCommand::class       => DbMigrateCommandFactory::class,
                DbMigrateCreateCommand::class => DbMigrateCreateCommandFactory::class,
            ],
        ];
    }

    /** @return array<string, array<string, string>> */
    public function getCliConfig(): array
    {
        return [
            'commands' => [
                'db:migrate'        => DbMigrateCommand::class,
                'db:migrate:create' => DbMigrateCreateCommand::class,
            ],
        ];
    }
}
