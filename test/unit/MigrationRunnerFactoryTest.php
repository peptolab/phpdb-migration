<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Migration\MigrationRunnerFactory;
use PhpDb\Migration\MismatchStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class MigrationRunnerFactoryTest extends TestCase
{
    private MigrationRunnerFactory $factory;

    #[Test]
    public function buildsRunnerFromFullConfig(): void
    {
        $adapter  = $this->createMock(AdapterInterface::class);
        $metadata = $this->createMock(MetadataInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static fn(string $id) => match ($id) {
                'config' => ['phpdb-migration' => [
                    'adapter_service'      => 'my_adapter',
                    'migrations_path'      => '/tmp/migrations',
                    'migrations_namespace' => 'App\\Custom',
                    'resolution'           => MismatchStrategy::Alter,
                ]],
                'my_adapter'             => $adapter,
                MetadataInterface::class => $metadata,
                default                  => self::fail("Unexpected container->get({$id})"),
            });
        $container->method('has')
            ->with(MetadataInterface::class)
            ->willReturn(true);

        $runner = ($this->factory)($container);

        static::assertInstanceOf(MigrationRunner::class, $runner);
        static::assertSame(MismatchStrategy::Alter, $runner->getMismatchStrategy());
    }

    #[Test]
    public function nonStringAdapterServiceFallsBackToDefault(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static fn(string $id) => match ($id) {
                'config'                => ['phpdb-migration' => ['adapter_service' => 123]],
                AdapterInterface::class => $adapter,
                default                 => self::fail("Unexpected container->get({$id})"),
            });
        $container->method('has')->willReturn(false);

        $runner = ($this->factory)($container);

        static::assertInstanceOf(MigrationRunner::class, $runner);
    }

    #[Test]
    public function resolutionStringIsConvertedToEnum(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static fn(string $id) => match ($id) {
                'config'                => ['phpdb-migration' => ['resolution' => 'ignore']],
                AdapterInterface::class => $adapter,
                default                 => self::fail("Unexpected container->get({$id})"),
            });
        $container->method('has')->willReturn(false);

        $runner = ($this->factory)($container);

        static::assertSame(MismatchStrategy::Ignore, $runner->getMismatchStrategy());
    }

    #[Test]
    public function usesDefaultsWhenConfigIsMissing(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(static fn(string $id) => match ($id) {
                'config'                => [],
                AdapterInterface::class => $adapter,
                default                 => self::fail("Unexpected container->get({$id})"),
            });
        $container->method('has')->willReturn(false);

        $runner = ($this->factory)($container);

        static::assertInstanceOf(MigrationRunner::class, $runner);
        static::assertSame(MismatchStrategy::Report, $runner->getMismatchStrategy());
    }

    protected function setUp(): void
    {
        $this->factory = new MigrationRunnerFactory();
    }
}
