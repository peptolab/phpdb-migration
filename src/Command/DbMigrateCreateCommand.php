<?php

declare(strict_types=1);

namespace PhpDb\Migration\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function date;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function preg_replace;
use function sprintf;
use function str_replace;
use function ucwords;

class DbMigrateCreateCommand extends Command
{
    protected static ?string $defaultName = 'db:migrate:create';

    protected static ?string $defaultDescription = 'Create a new database migration';

    public function __construct(
        private readonly string $migrationsPath,
        private readonly string $migrationsNamespace,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Creates a new migration file with boilerplate code')
            ->addArgument(
                'description',
                InputArgument::REQUIRED,
                'Description of the migration (e.g., "Add tags tables")',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $description */
        $description = $input->getArgument('description');
        $timestamp   = date('YmdHis');
        $className   = $this->descriptionToClassName($description);
        $filename    = sprintf('Version%s_%s.php', $timestamp, $className);

        // Auto-create migrations directory if missing
        if (! is_dir($this->migrationsPath)) {
            if (! mkdir($this->migrationsPath, 0755, true)) {
                $io->error('Failed to create migrations directory: ' . $this->migrationsPath);

                return Command::FAILURE;
            }

            $io->note('Created migrations directory: ' . $this->migrationsPath);
        }

        $filePath = $this->migrationsPath . '/' . $filename;
        $content  = $this->generateMigrationContent($timestamp, $className, $description);

        if (file_put_contents($filePath, $content) === false) {
            $io->error('Failed to create migration file');

            return Command::FAILURE;
        }

        $io->success('Created migration: ' . $filename);
        $io->text('Path: ' . $filePath);

        return Command::SUCCESS;
    }

    private function descriptionToClassName(string $description): string
    {
        // Remove special characters, keep alphanumeric and spaces
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $description);

        // Convert to PascalCase
        return str_replace(' ', '', ucwords((string) $clean));
    }

    private function generateMigrationContent(
        string $timestamp,
        string $className,
        string $description,
    ): string {
        $namespace = $this->migrationsNamespace;

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use PhpDb\Migration\AbstractMigration;
            use PhpDb\Sql\Ddl\Column;
            use PhpDb\Sql\Ddl\Constraint;
            use PhpDb\Sql\Ddl\CreateTable;

            class Version{$timestamp}_{$className} extends AbstractMigration
            {
                public function getVersion(): string
                {
                    return '{$timestamp}';
                }

                public function getDescription(): string
                {
                    return '{$description}';
                }

                protected function define(): void
                {
                    // Example: Create a new table
                    // \$this->ensureTable('my_table', function (CreateTable \$table) {
                    //     \$id = new Column\Integer('id');
                    //     \$id->setOption('unsigned', true);
                    //     \$id->setOption('auto_increment', true);
                    //     \$table->addColumn(\$id);
                    //
                    //     \$table->addColumn(new Column\Varchar('name', 255));
                    //
                    //     \$createdAt = new Column\Datetime('created_at');
                    //     \$createdAt->setDefault('CURRENT_TIMESTAMP');
                    //     \$table->addColumn(\$createdAt);
                    //
                    //     \$table->addConstraint(new Constraint\PrimaryKey(['id']));
                    // });

                    // Example: Add a column to existing table
                    // \$this->ensureColumn('users', new Column\Varchar('nickname', 100));

                    // Example: Add an index
                    // \$this->ensureIndex('users', 'idx_users_nickname', ['nickname']);

                    // Example: Add a foreign key
                    // \$this->ensureForeignKey(
                    //     'posts',           // table
                    //     'fk_posts_user',   // constraint name
                    //     'user_id',         // column
                    //     'users',           // reference table
                    //     'id',              // reference column
                    //     'CASCADE',         // on delete
                    // );
                }
            }

            PHP;
    }
}
