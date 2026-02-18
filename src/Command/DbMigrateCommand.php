<?php

declare(strict_types=1);

namespace PhpDb\Migration\Command;

use Exception;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Migration\MismatchStrategy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

class DbMigrateCommand extends Command
{
    protected static ?string $defaultName = 'db:migrate';

    protected static ?string $defaultDescription = 'Run database migrations';

    public function __construct(
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Run pending database migrations or view migration status')
            ->addOption(
                'status',
                's',
                InputOption::VALUE_NONE,
                'Show migration status instead of running migrations',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview SQL without executing (dry run)',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Run without confirmation (for CI/CD)',
            )
            ->addOption(
                'resolution-strategy',
                'r',
                InputOption::VALUE_REQUIRED,
                'Mismatch resolution strategy: ignore, report, alter (default: from config)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Database Migrations');

        try {
            // Ensure migrations table exists
            $this->runner->ensureMigrationsTable();

            if ($input->getOption('status')) {
                return $this->showStatus($io);
            }

            if ($input->getOption('dry-run')) {
                return $this->showPreview($io);
            }

            return $this->runMigrations($io, $input);
        } catch (Exception $e) {
            $io->error('Migration error: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    private function showStatus(SymfonyStyle $io): int
    {
        $status = $this->runner->getStatus();

        if (empty($status)) {
            $io->info('No migrations found.');

            return Command::SUCCESS;
        }

        $io->section('Migration Status');

        $rows         = [];
        $pendingCount = 0;

        foreach ($status as $migration) {
            $statusText = $migration['status'] === 'applied'
                ? '<fg=green>Applied</>'
                : '<fg=yellow>Pending</>';

            if ($migration['status'] === 'pending') {
                $pendingCount++;
            }

            $rows[] = [
                $migration['version'],
                $migration['description'],
                $statusText,
                $migration['executed_at'] ?? '-',
            ];
        }

        $io->table(
            ['Version', 'Description', 'Status', 'Executed At'],
            $rows,
        );

        if ($pendingCount > 0) {
            $io->note(sprintf('%d pending migration(s) to run.', $pendingCount));
        } else {
            $io->success('All migrations have been applied.');
        }

        return Command::SUCCESS;
    }

    private function showPreview(SymfonyStyle $io): int
    {
        $previews = $this->runner->previewPending();

        if (empty($previews)) {
            $io->success('No pending migrations.');

            return Command::SUCCESS;
        }

        $io->section('Migration Preview (Dry Run)');

        foreach ($previews as $preview) {
            $io->writeln(sprintf(
                '<info>[%s]</info> %s',
                $preview['version'],
                $preview['description'],
            ));

            if (empty($preview['sql'])) {
                $io->writeln('  <comment>No SQL statements (already applied or skipped)</comment>');
            } else {
                foreach ($preview['sql'] as $sql) {
                    $io->writeln('  <fg=gray>' . $sql . '</>');
                }
            }

            $io->newLine();
        }

        $io->note('This was a dry run. No changes were made to the database.');

        return Command::SUCCESS;
    }

    private function runMigrations(SymfonyStyle $io, InputInterface $input): int
    {
        $pending = $this->runner->getPendingMigrations();

        if (empty($pending)) {
            $io->success('No pending migrations.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('Found %d pending migration(s)', count($pending)));

        // Show what will be run
        foreach ($pending as $migration) {
            $io->writeln(sprintf(
                '  • <info>[%s]</info> %s',
                $migration->getVersion(),
                $migration->getDescription(),
            ));
        }

        $io->newLine();

        // Show resolution strategy
        $strategyOption = $input->getOption('resolution-strategy');
        $strategy       = $strategyOption !== null
            ? MismatchStrategy::from($strategyOption)
            : $this->runner->getMismatchStrategy();

        $io->writeln(sprintf('  Resolution strategy: <comment>%s</comment>', $strategy->value));
        $io->newLine();

        // Confirm unless --force
        $force = $input->getOption('force');

        if (! $force) {
            if (! $io->confirm('Run these migrations?', false)) {
                $io->warning('Migration cancelled.');

                return Command::SUCCESS;
            }
        }

        $io->section('Running Migrations');

        $results  = $this->runner->runPending();
        $failures = 0;

        foreach ($results as $result) {
            $migration = $result['result'];
            $prefix    = sprintf('[%s] %s', $result['version'], $result['description']);

            if ($migration->isFailed()) {
                $io->error($prefix);
                $io->writeln(sprintf('  Error: %s', $migration->errorMessage));
                $failures++;
                continue;
            }

            if ($migration->isSkipped()) {
                $io->writeln(sprintf('<comment>%s - Skipped</comment>', $prefix));

                foreach ($migration->skippedOperations as $op) {
                    $io->writeln(sprintf('  <fg=gray>%s</>', $op));
                }
            } else {
                $io->writeln(sprintf('<info>%s - Success</info>', $prefix));

                if (! empty($migration->executedSql)) {
                    foreach ($migration->executedSql as $sql) {
                        $io->writeln(sprintf('  <fg=gray>✓ %s</>', $sql));
                    }
                }

                if (! empty($migration->skippedOperations)) {
                    foreach ($migration->skippedOperations as $op) {
                        $io->writeln(sprintf('  <fg=gray>○ %s</>', $op));
                    }
                }
            }

            // Display mismatches when strategy is report
            if ($migration->hasMismatches()) {
                $io->newLine();
                $io->writeln('  <fg=yellow>Definition mismatches detected:</>');

                foreach ($migration->mismatches as $mismatch) {
                    $io->writeln(sprintf(
                        '    ⚠ %s.%s [%s]: expected=%s actual=%s',
                        $mismatch['table'],
                        $mismatch['column'],
                        $mismatch['field'],
                        $mismatch['expected'],
                        $mismatch['actual'],
                    ));
                }
            }
        }

        $io->newLine();

        if ($failures > 0) {
            $io->error(sprintf('%d migration(s) failed.', $failures));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d migration(s) completed successfully.', count($results)));

        return Command::SUCCESS;
    }
}
