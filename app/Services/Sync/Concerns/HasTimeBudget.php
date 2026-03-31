<?php

namespace App\Services\Sync\Concerns;

trait HasTimeBudget
{
    protected float $budgetStartedAt;

    protected int $timeBudgetSeconds = 210;

    protected function startTimeBudget(): void
    {
        $this->budgetStartedAt = microtime(true);
    }

    protected function hasTimeRemaining(): bool
    {
        return (microtime(true) - $this->budgetStartedAt) < $this->timeBudgetSeconds;
    }

    protected function elapsedSeconds(): float
    {
        return round(microtime(true) - $this->budgetStartedAt, 1);
    }
}
