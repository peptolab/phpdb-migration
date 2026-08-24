<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Command;

use PhpDb\Migration\Command\DbMigrateCommand;
use PhpDb\Migration\MigrationInterface;
use PhpDb\Migration\MigrationResult;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Migration\MismatchStrategy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DbMigrateCommandTest extends TestCase
{
    public function testStatusOptionShowsNoMigrationsMessage(): void
    {
        $runner = $this->createRunner();
        $runner->method('getStatus')->willReturn([]);

        $exitCode = $this->execute($runner, ['--status' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testStatusOptionShowsPendingAndAppliedMigrations(): void
    {
        $runner = $this->createRunner();
        $runner->method('getStatus')->willReturn([
            [
                'version'     => '20260101000000',
                'description' => 'Create users',
                'status'      => 'applied',
                'executed_at' => '2026-01-01 00:00:00',
            ],
            [
                'version'     => '20260102000000',
                'description' => 'Add index',
                'status'      => 'pending',
                'executed_at' => null,
            ],
        ]);

        $tester   = $this->tester($runner);
        $exitCode = $tester->execute(['--status' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('1 pending migration(s) to run.', $tester->getDisplay());
    }

    public function testStatusOptionShowsAllAppliedMessage(): void
    {
        $runner = $this->createRunner();
        $runner->method('getStatus')->willReturn([
            [
                'version'     => '20260101000000',
                'description' => 'Create users',
                'status'      => 'applied',
                'executed_at' => '2026-01-01 00:00:00',
            ],
        ]);

        $tester = $this->tester($runner);
        $tester->execute(['--status' => true]);

        self::assertStringContainsString('All migrations have been applied.', $tester->getDisplay());
    }

    public function testDryRunShowsNoPendingMessage(): void
    {
        $runner = $this->createRunner();
        $runner->method('previewPending')->willReturn([]);

        $exitCode = $this->execute($runner, ['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testDryRunShowsSqlPreview(): void
    {
        $runner = $this->createRunner();
        $runner->method('previewPending')->willReturn([
            ['version' => '20260101000000', 'description' => 'Create users', 'sql' => ['CREATE TABLE users (...)']],
            ['version' => '20260102000000', 'description' => 'Already applied', 'sql' => []],
        ]);

        $tester = $this->tester($runner);
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('CREATE TABLE users', $display);
        self::assertStringContainsString('No SQL statements', $display);
        self::assertStringContainsString('This was a dry run.', $display);
    }

    public function testRunShowsNoPendingMessage(): void
    {
        $runner = $this->createRunner();
        $runner->method('getPendingMigrations')->willReturn([]);

        $exitCode = $this->execute($runner, []);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    public function testRunCancelledWithoutForceWhenNotConfirmed(): void
    {
        $runner = $this->createRunner();
        $runner->method('getPendingMigrations')->willReturn([$this->createPendingMigration()]);
        $runner->method('getMismatchStrategy')->willReturn(MismatchStrategy::Report);
        $runner->expects(self::never())->method('runPending');

        $tester = $this->tester($runner);
        $tester->setInputs(['no']);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Migration cancelled.', $tester->getDisplay());
    }

    public function testRunWithForceExecutesAndReportsSuccessSkippedAndMismatches(): void
    {
        $successResult = MigrationResult::success(
            ['CREATE TABLE users (...)'],
            ['Column already exists'],
            [['table' => 'users', 'column' => 'email', 'field' => 'type', 'expected' => 'varchar', 'actual' => 'text']],
        );
        $skippedResult = MigrationResult::skipped(['Already applied']);

        $runner = $this->createRunner();
        $runner->method('getPendingMigrations')->willReturn([$this->createPendingMigration()]);
        $runner->method('getMismatchStrategy')->willReturn(MismatchStrategy::Report);
        $runner->method('runPending')->willReturn([
            ['version' => '20260101000000', 'description' => 'Create users', 'result' => $successResult],
            ['version' => '20260102000000', 'description' => 'Already applied', 'result' => $skippedResult],
        ]);

        $tester   = $this->tester($runner);
        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Success', $display);
        self::assertStringContainsString('Skipped', $display);
        self::assertStringContainsString('Definition mismatches detected', $display);
        self::assertStringContainsString('2 migration(s) completed successfully.', $display);
    }

    public function testRunReportsFailures(): void
    {
        $runner = $this->createRunner();
        $runner->method('getPendingMigrations')->willReturn([$this->createPendingMigration()]);
        $runner->method('getMismatchStrategy')->willReturn(MismatchStrategy::Report);
        $runner->method('runPending')->willReturn([
            ['version' => '20260101000000', 'description' => 'Create users', 'result' => MigrationResult::failed('DB exploded')],
        ]);

        $tester   = $this->tester($runner);
        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Error: DB exploded', $display);
        self::assertStringContainsString('1 migration(s) failed.', $display);
    }

    public function testResolutionStrategyOptionOverridesRunnerDefault(): void
    {
        $runner = $this->createRunner();
        $runner->method('getPendingMigrations')->willReturn([$this->createPendingMigration()]);
        $runner->expects(self::never())->method('getMismatchStrategy');
        $runner->method('runPending')->willReturn([
            ['version' => '20260101000000', 'description' => 'Create users', 'result' => MigrationResult::success()],
        ]);

        $tester = $this->tester($runner);
        $tester->execute(['--force' => true, '--resolution-strategy' => 'alter']);

        self::assertStringContainsString('alter', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureOnException(): void
    {
        $runner = $this->createRunner();
        $runner->method('ensureMigrationsTable')->willThrowException(new RuntimeException('DB down'));

        $tester   = $this->tester($runner);
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Migration error: DB down', $tester->getDisplay());
    }

    private function createPendingMigration(): MigrationInterface&MockObject
    {
        $migration = $this->createMock(MigrationInterface::class);
        $migration->method('getVersion')->willReturn('20260101000000');
        $migration->method('getDescription')->willReturn('Create users');

        return $migration;
    }

    private function createRunner(): MigrationRunner&MockObject
    {
        return $this->createMock(MigrationRunner::class);
    }

    private function tester(MigrationRunner&MockObject $runner): CommandTester
    {
        return new CommandTester(new DbMigrateCommand($runner));
    }

    /** @param array<string, mixed> $input */
    private function execute(MigrationRunner&MockObject $runner, array $input): int
    {
        return $this->tester($runner)->execute($input);
    }
}
