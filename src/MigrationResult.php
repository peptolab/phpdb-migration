<?php

declare(strict_types=1);

namespace PhpDb\Migration;

use function count;

/**
 * DTO representing the result of a migration execution.
 */
final class MigrationResult
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    /**
     * @param string        $status            One of the STATUS_* constants
     * @param array<string> $executedSql       SQL statements that were executed
     * @param array<string> $skippedOperations Operations that were skipped (already applied)
     * @param string|null   $errorMessage      Error message if status is FAILED
     * @param array<array<string, string>> $mismatches Detected definition mismatches
     */
    public function __construct(
        public readonly string $status,
        public readonly array $executedSql = [],
        public readonly array $skippedOperations = [],
        public readonly ?string $errorMessage = null,
        public readonly array $mismatches = [],
    ) {
    }

    /**
     * Create a successful result.
     *
     * @param array<string> $executedSql
     * @param array<string> $skippedOperations
     * @param array<array{table: string, column: string, field: string, expected: string, actual: string}> $mismatches
     */
    public static function success(
        array $executedSql = [],
        array $skippedOperations = [],
        array $mismatches = [],
    ): self {
        return new self(self::STATUS_SUCCESS, $executedSql, $skippedOperations, null, $mismatches);
    }

    /**
     * Create a skipped result (migration was already applied).
     *
     * @param array<string> $skippedOperations
     * @param array<array{table: string, column: string, field: string, expected: string, actual: string}> $mismatches
     */
    public static function skipped(array $skippedOperations = [], array $mismatches = []): self
    {
        return new self(self::STATUS_SKIPPED, [], $skippedOperations, null, $mismatches);
    }

    /**
     * Create a failed result.
     *
     * @param array<string> $executedSql SQL executed before failure
     */
    public static function failed(string $errorMessage, array $executedSql = []): self
    {
        return new self(self::STATUS_FAILED, $executedSql, [], $errorMessage);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if any changes were made.
     */
    public function hasChanges(): bool
    {
        return count($this->executedSql) > 0;
    }

    /**
     * Check if any definition mismatches were detected.
     */
    public function hasMismatches(): bool
    {
        return count($this->mismatches) > 0;
    }
}
