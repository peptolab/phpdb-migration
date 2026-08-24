<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Migration\MigrationRunnerFactory;
use PhpDb\Migration\MismatchStrategy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class MigrationRunnerFactoryTest extends TestCase
{
    private MigrationRunnerFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new MigrationRunnerFactory();
    }

    public function testBuildsRunnerFromFullConfig(): void
    {
        $adapter  = $this->createMock(AdapterInterface::class);
        $metadata = $this->createMock(MetadataInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function (string $id) use ($adapter, $metadata) {
                return match ($id) {
                    'config'                => ['phpdb-migration' => [
                        'adapter_service'      => 'my_adapter',
                        'migrations_path'      => '/tmp/migrations',
                        'migrations_namespace' => 'App\\Custom',
                        'resolution'           => MismatchStrategy::Alter,
                    ]],
                    'my_adapter'            => $adapter,
                    MetadataInterface::class => $metadata,
                    default                 => self::fail("Unexpected container->get({$id})"),
                };
            });
        $container->method('has')
            ->with(MetadataInterface::class)
            ->willReturn(true);

        $runner = ($this->factory)($container);

        self::assertInstanceOf(MigrationRunner::class, $runner);
        self::assertSame(MismatchStrategy::Alter, $runner->getMismatchStrategy());
    }

    public function testResolutionStringIsConvertedToEnum(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function (string $id) use ($adapter) {
                return match ($id) {
                    'config'                     => ['phpdb-migration' => ['resolution' => 'ignore']],
                    AdapterInterface::class      => $adapter,
                    default                      => self::fail("Unexpected container->get({$id})"),
                };
            });
        $container->method('has')->willReturn(false);

        $runner = ($this->factory)($container);

        self::assertSame(MismatchStrategy::Ignore, $runner->getMismatchStrategy());
    }

    public function testUsesDefaultsWhenConfigIsMissing(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function (string $id) use ($adapter) {
                return match ($id) {
                    'config'                 => [],
                    AdapterInterface::class  => $adapter,
                    default                  => self::fail("Unexpected container->get({$id})"),
                };
            });
        $container->method('has')->willReturn(false);

        $runner = ($this->factory)($container);

        self::assertInstanceOf(MigrationRunner::class, $runner);
        self::assertSame(MismatchStrategy::Report, $runner->getMismatchStrategy());
    }

    public function testNonStringAdapterServiceFallsBackToDefault(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function (string $id) use ($adapter) {
                return match ($id) {
                    'config'                 => ['phpdb-migration' => ['adapter_service' => 123]],
                    AdapterInterface::class  => $adapter,
                    default                  => self::fail("Unexpected container->get({$id})"),
                };
            });
        $container->method('has')->willReturn(false);

        $runner = ($this->factory)($container);

        self::assertInstanceOf(MigrationRunner::class, $runner);
    }
}
