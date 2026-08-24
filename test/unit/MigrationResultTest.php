<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Migration\MigrationResult;
use PHPUnit\Framework\TestCase;

class MigrationResultTest extends TestCase
{
    public function testSuccessFactory(): void
    {
        $result = MigrationResult::success(['CREATE TABLE test'], ['Skipped op']);

        self::assertTrue($result->isSuccess());
        self::assertFalse($result->isSkipped());
        self::assertFalse($result->isFailed());
        self::assertTrue($result->hasChanges());
        self::assertSame(MigrationResult::STATUS_SUCCESS, $result->status);
        self::assertSame(['CREATE TABLE test'], $result->executedSql);
        self::assertSame(['Skipped op'], $result->skippedOperations);
        self::assertNull($result->errorMessage);
    }

    public function testSuccessWithoutChanges(): void
    {
        $result = MigrationResult::success();

        self::assertTrue($result->isSuccess());
        self::assertFalse($result->hasChanges());
        self::assertSame([], $result->executedSql);
    }

    public function testSkippedFactory(): void
    {
        $result = MigrationResult::skipped(['Already exists']);

        self::assertTrue($result->isSkipped());
        self::assertFalse($result->isSuccess());
        self::assertFalse($result->isFailed());
        self::assertFalse($result->hasChanges());
        self::assertSame(['Already exists'], $result->skippedOperations);
        self::assertSame([], $result->executedSql);
    }

    public function testFailedFactory(): void
    {
        $result = MigrationResult::failed('Something went wrong', ['partial SQL']);

        self::assertTrue($result->isFailed());
        self::assertFalse($result->isSuccess());
        self::assertFalse($result->isSkipped());
        self::assertTrue($result->hasChanges());
        self::assertSame('Something went wrong', $result->errorMessage);
        self::assertSame(['partial SQL'], $result->executedSql);
    }

    public function testFailedWithoutPriorSql(): void
    {
        $result = MigrationResult::failed('Error');

        self::assertTrue($result->isFailed());
        self::assertFalse($result->hasChanges());
    }

    public function testHasMismatchesWithMismatches(): void
    {
        $mismatches = [
            [
                'table'    => 'users',
                'column'   => 'email',
                'field'    => 'type',
                'expected' => 'varchar',
                'actual'   => 'text',
            ],
        ];

        $result = MigrationResult::success([], [], $mismatches);

        self::assertTrue($result->hasMismatches());
        self::assertCount(1, $result->mismatches);
        self::assertSame('users', $result->mismatches[0]['table']);
        self::assertSame('email', $result->mismatches[0]['column']);
    }

    public function testHasMismatchesWithoutMismatches(): void
    {
        $result = MigrationResult::success();

        self::assertFalse($result->hasMismatches());
        self::assertSame([], $result->mismatches);
    }

    public function testSkippedWithMismatches(): void
    {
        $mismatches = [
            [
                'table'    => 'users',
                'column'   => 'name',
                'field'    => 'length',
                'expected' => '255',
                'actual'   => '100',
            ],
        ];

        $result = MigrationResult::skipped(['Table already exists'], $mismatches);

        self::assertTrue($result->isSkipped());
        self::assertTrue($result->hasMismatches());
        self::assertCount(1, $result->mismatches);
    }

    public function testStatusConstants(): void
    {
        self::assertSame('success', MigrationResult::STATUS_SUCCESS);
        self::assertSame('skipped', MigrationResult::STATUS_SKIPPED);
        self::assertSame('failed', MigrationResult::STATUS_FAILED);
    }
}
