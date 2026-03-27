<?php

namespace App\Console\Commands;

use App\Services\ProductPortfolioService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:analyse-portfolio {--detail : Show individual product drilldown}')]
#[Description('Analyse product portfolio: acquisition profiles, transition matrix, margins, timing')]
class AnalyseProductPortfolioCommand extends Command
{
    public function handle(ProductPortfolioService $service): int
    {
        $detail = $this->option('detail');

        $this->scorecard($service);
        $this->acquisitionProfile($service, $detail);
        $this->transitionMatrix($service, $detail);
        $this->marginProfile($service, $detail);
        $this->timingProfile($service, $detail);

        return self::SUCCESS;
    }

    private function scorecard(ProductPortfolioService $service): void
    {
        $this->newLine();
        $this->info('═══ PORTFOLIO SCORECARD ═══');
        $this->newLine();

        $rows = $service->portfolioScorecard();

        $this->table(
            ['Category', 'Role', 'Units', 'Revenue', 'Margin', 'M%', '€/unit', '1st Cust', 'Repeat%', 'Avg days', '≤90d', 'Next→'],
            array_map(fn ($r) => [
                $r->label,
                $r->portfolio_role,
                number_format($r->units_sold),
                '€'.number_format($r->revenue, 0),
                '€'.number_format($r->gross_margin, 0),
                $r->margin_pct.'%',
                '€'.number_format($r->margin_per_unit, 2),
                number_format($r->first_order_customers),
                $r->repeat_rate ? $r->repeat_rate.'%' : '—',
                $r->avg_days_to_repeat ?? '—',
                $r->pct_within_90d ? $r->pct_within_90d.'%' : '—',
                $r->top_next_category ? $r->top_next_category.' ('.$r->top_next_pct.'%)' : '—',
            ], $rows)
        );
    }

    private function acquisitionProfile(ProductPortfolioService $service, bool $detail): void
    {
        $this->newLine();
        $this->info('═══ ACQUISITION PROFILE — Which products bring customers in and drive repeat? ═══');
        $this->newLine();

        $rows = $service->acquisitionProfile($detail);

        $this->table(
            array_filter(['Category', $detail ? 'Product' : null, '1st Order Cust', 'Repeated', 'Repeat%', 'Units', 'Revenue 1st']),
            array_map(fn ($r) => array_filter([
                $r->product_category,
                $detail ? $r->product_title : null,
                number_format($r->first_order_customers),
                number_format($r->repeat_customers),
                $r->repeat_rate.'%',
                number_format($r->units_first),
                '€'.number_format($r->revenue_first, 0),
            ], fn ($v) => $v !== null), $rows)
        );
    }

    private function transitionMatrix(ProductPortfolioService $service, bool $detail): void
    {
        $this->newLine();
        $this->info('═══ TRANSITION MATRIX — What do customers buy second after each category? ═══');
        $this->newLine();

        $rows = $service->transitionMatrix($detail);

        $currentFrom = null;
        foreach ($rows as $r) {
            if ($r->from_category !== $currentFrom) {
                $currentFrom = $r->from_category;
                $this->newLine();
                $this->line("  <comment>{$currentFrom}</comment> →");
            }
            $bar = str_repeat('█', (int) round($r->pct_of_from / 2));
            $this->line("    {$bar} {$r->to_category} ({$r->transitions}x, {$r->pct_of_from}%)");
        }
    }

    private function marginProfile(ProductPortfolioService $service, bool $detail): void
    {
        $this->newLine(2);
        $this->info('═══ MARGIN PROFILE — Revenue and profitability per category ═══');
        $this->newLine();

        $rows = $service->marginProfile($detail);

        $this->table(
            array_filter(['Category', $detail ? 'Product' : null, 'Units', 'Revenue', 'COGS', 'Margin', '€/unit', 'M%']),
            array_map(fn ($r) => array_filter([
                $r->product_category,
                $detail ? $r->product_title : null,
                number_format($r->total_units),
                '€'.number_format($r->revenue, 0),
                '€'.number_format($r->cogs, 0),
                '€'.number_format($r->gross_margin, 0),
                '€'.number_format($r->margin_per_unit, 2),
                $r->margin_pct.'%',
            ], fn ($v) => $v !== null), $rows)
        );
    }

    private function timingProfile(ProductPortfolioService $service, bool $detail): void
    {
        $this->newLine();
        $this->info('═══ TIMING PROFILE — Days to second order per first-order category ═══');
        $this->newLine();

        $rows = $service->timingProfile($detail);

        $this->table(
            array_filter(['Category', $detail ? 'Product' : null, 'Repeaters', 'Avg days', '≤30d', '≤60d', '≤90d', '≤180d']),
            array_map(fn ($r) => array_filter([
                $r->product_category,
                $detail ? $r->product_title : null,
                number_format($r->repeat_customers),
                $r->avg_days,
                $r->within_30d.' ('.$r->pct_30d.'%)',
                $r->within_60d.' ('.$r->pct_60d.'%)',
                $r->within_90d.' ('.$r->pct_90d.'%)',
                $r->within_180d.' ('.$r->pct_180d.'%)',
            ], fn ($v) => $v !== null), $rows)
        );
    }
}
