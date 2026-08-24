<?php

declare(strict_types=1);

namespace PhpDbTest\Migration;

use PhpDb\Migration\MigrationResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MigrationResultTest extends TestCase
{
    #[Test]
    public function failedFactory(): void
    {
        $result = MigrationResult::failed('Something went wrong', ['partial SQL']);

        static::assertTrue($result->isFailed());
        static::assertFalse($result->isSuccess());
        static::assertFalse($result->isSkipped());
        static::assertTrue($result->hasChanges());
        static::assertSame('Something went wrong', $result->errorMessage);
        static::assertSame(['partial SQL'], $result->executedSql);
    }

    #[Test]
    public function failedWithoutPriorSql(): void
    {
        $result = MigrationResult::failed('Error');

        static::assertTrue($result->isFailed());
        static::assertFalse($result->hasChanges());
    }

    #[Test]
    public function hasMismatchesWithMismatches(): void
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

        static::assertTrue($result->hasMismatches());
        static::assertCount(1, $result->mismatches);
        static::assertSame('users', $result->mismatches[0]['table']);
        static::assertSame('email', $result->mismatches[0]['column']);
    }

    #[Test]
    public function hasMismatchesWithoutMismatches(): void
    {
        $result = MigrationResult::success();

        static::assertFalse($result->hasMismatches());
        static::assertSame([], $result->mismatches);
    }

    #[Test]
    public function skippedFactory(): void
    {
        $result = MigrationResult::skipped(['Already exists']);

        static::assertTrue($result->isSkipped());
        static::assertFalse($result->isSuccess());
        static::assertFalse($result->isFailed());
        static::assertFalse($result->hasChanges());
        static::assertSame(['Already exists'], $result->skippedOperations);
        static::assertSame([], $result->executedSql);
    }

    #[Test]
    public function skippedWithMismatches(): void
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

        static::assertTrue($result->isSkipped());
        static::assertTrue($result->hasMismatches());
        static::assertCount(1, $result->mismatches);
    }

    #[Test]
    public function statusConstants(): void
    {
        static::assertSame('success', MigrationResult::STATUS_SUCCESS);
        static::assertSame('skipped', MigrationResult::STATUS_SKIPPED);
        static::assertSame('failed', MigrationResult::STATUS_FAILED);
    }

    #[Test]
    public function successFactory(): void
    {
        $result = MigrationResult::success(['CREATE TABLE test'], ['Skipped op']);

        static::assertTrue($result->isSuccess());
        static::assertFalse($result->isSkipped());
        static::assertFalse($result->isFailed());
        static::assertTrue($result->hasChanges());
        static::assertSame(MigrationResult::STATUS_SUCCESS, $result->status);
        static::assertSame(['CREATE TABLE test'], $result->executedSql);
        static::assertSame(['Skipped op'], $result->skippedOperations);
        static::assertNull($result->errorMessage);
    }

    #[Test]
    public function successWithoutChanges(): void
    {
        $result = MigrationResult::success();

        static::assertTrue($result->isSuccess());
        static::assertFalse($result->hasChanges());
        static::assertSame([], $result->executedSql);
    }
}
