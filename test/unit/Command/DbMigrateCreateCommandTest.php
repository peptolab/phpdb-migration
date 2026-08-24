<?php

declare(strict_types=1);

namespace PhpDbTest\Migration\Command;

use PhpDb\Migration\Command\DbMigrateCreateCommand;
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

    protected function setUp(): void
    {
        $this->migrationsPath = sys_get_temp_dir() . '/phpdb-migration-test-' . sha1((string) __CLASS__);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->migrationsPath)) {
            return;
        }

        foreach (glob($this->migrationsPath . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->migrationsPath);
    }

    public function testCreatesMigrationFileAndDirectory(): void
    {
        self::assertDirectoryDoesNotExist($this->migrationsPath);

        $command = new DbMigrateCreateCommand($this->migrationsPath, 'App\\Migration');
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute(['description' => 'Add tags table']);

        self::assertSame(0, $exitCode);
        self::assertDirectoryExists($this->migrationsPath);

        $files = glob($this->migrationsPath . '/Version*_AddTagsTable.php');
        self::assertNotFalse($files);
        self::assertCount(1, $files);

        $content = file_get_contents($files[0]);
        self::assertNotFalse($content);
        self::assertStringContainsString('namespace App\\Migration;', $content);
        self::assertStringContainsString('extends AbstractMigration', $content);
        self::assertStringContainsString("return 'Add tags table';", $content);
        self::assertStringContainsString('Created migration:', $tester->getDisplay());
    }

    public function testFailsWhenMigrationsDirectoryCannotBeCreated(): void
    {
        // A regular file already occupying the target path forces mkdir() to fail.
        file_put_contents($this->migrationsPath, '');

        $command = new DbMigrateCreateCommand($this->migrationsPath, 'App\\Migration');
        $tester  = new CommandTester($command);

        // mkdir() emits a PHP E_WARNING for the failure this test deliberately
        // forces; silence it rather than let it surface as a test warning.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $exitCode = $tester->execute(['description' => 'Add tags table']);
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Failed to create migrations directory', $tester->getDisplay());

        unlink($this->migrationsPath);
    }

    public function testStripsSpecialCharactersFromClassName(): void
    {
        $command = new DbMigrateCreateCommand($this->migrationsPath, 'App\\Migration');
        $tester  = new CommandTester($command);

        $tester->execute(['description' => "Add user's email (verified)!"]);

        $files = glob($this->migrationsPath . '/Version*.php');
        self::assertNotFalse($files);
        self::assertCount(1, $files);
        self::assertStringContainsString('AddUsersEmailVerified', $files[0]);
    }
}
