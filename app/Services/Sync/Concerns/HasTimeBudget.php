<?php

namespace App\Services\Sync\Concerns;

trait HasTimeBudget
{
    protected float $budgetStartedAt;

    protected int $timeBudgetSeconds = 210;

    /** Memory usage threshold as fraction of PHP memory_limit (0.0–1.0) */
    protected float $memoryThreshold = 0.80;

    protected function startTimeBudget(): void
    {
        $this->budgetStartedAt = microtime(true);
    }

    protected function hasTimeRemaining(): bool
    {
        return (microtime(true) - $this->budgetStartedAt) < $this->timeBudgetSeconds
            && $this->hasMemoryRemaining();
    }

    protected function hasMemoryRemaining(): bool
    {
        $limit = $this->getMemoryLimitBytes();

        if ($limit <= 0) {
            return true; // No limit set (-1)
        }

        return memory_get_usage(true) < (int) ($limit * $this->memoryThreshold);
    }

    protected function elapsedSeconds(): float
    {
        return round(microtime(true) - $this->budgetStartedAt, 1);
    }

    protected function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return -1;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
