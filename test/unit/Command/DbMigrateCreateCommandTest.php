<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Command;

use PhpDb\Migration\Command\DbMigrateCreateCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function restore_error_handler;
use function rmdir;
use function set_error_handler;
use function sha1;
use function sys_get_temp_dir;
use function unlink;

class DbMigrateCreateCommandTest extends TestCase
{
    private string $migrationsPath;

    #[Test]
    public function createsMigrationFileAndDirectory(): void
    {
        static::assertDirectoryDoesNotExist($this->migrationsPath);

        $command = new DbMigrateCreateCommand($this->migrationsPath, 'App\\Migration');
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute(['description' => 'Add tags table']);

        static::assertSame(0, $exitCode);
        static::assertDirectoryExists($this->migrationsPath);

        $files = glob("{$this->migrationsPath}/Version*_AddTagsTable.php");
        static::assertNotFalse($files);
        static::assertCount(1, $files);

        $content = file_get_contents($files[0]);
        static::assertNotFalse($content);
        static::assertStringContainsString('namespace App\\Migration;', $content);
        static::assertStringContainsString('extends AbstractMigration', $content);
        static::assertStringContainsString("return 'Add tags table';", $content);
        static::assertStringContainsString('Created migration:', $tester->getDisplay());
    }

    #[Test]
    public function failsWhenMigrationsDirectoryCannotBeCreated(): void
    {
        // A regular file already occupying the target path forces mkdir() to fail.
        file_put_contents($this->migrationsPath, '');

        $command = new DbMigrateCreateCommand($this->migrationsPath, 'App\\Migration');
        $tester  = new CommandTester($command);

        // mkdir() emits a PHP E_WARNING for the failure this test deliberately
        // forces; silence it rather than let it surface as a test warning.
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $exitCode = $tester->execute(['description' => 'Add tags table']);
        } finally {
            restore_error_handler();
        }

        static::assertSame(1, $exitCode);
        static::assertStringContainsString('Failed to create migrations directory', $tester->getDisplay());

        unlink($this->migrationsPath);
    }

    #[Test]
    public function stripsSpecialCharactersFromClassName(): void
    {
        $command = new DbMigrateCreateCommand($this->migrationsPath, 'App\\Migration');
        $tester  = new CommandTester($command);

        $tester->execute(['description' => "Add user's email (verified)!"]);

        $files = glob("{$this->migrationsPath}/Version*.php");
        static::assertNotFalse($files);
        static::assertCount(1, $files);
        static::assertStringContainsString('AddUsersEmailVerified', $files[0]);
    }

    protected function setUp(): void
    {
        $this->migrationsPath = sys_get_temp_dir() . '/phpdb-migration-test-' . sha1((string) __CLASS__);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->migrationsPath)) {
            return;
        }

        foreach (glob("{$this->migrationsPath}/*.php") ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->migrationsPath);
    }
}
