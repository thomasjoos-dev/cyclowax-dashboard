<?php

namespace App\Console\Commands;

use App\Services\Report\ProductPortfolioReportService;
use App\Services\Support\AnalysisPdfService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('products:overview-report {--since= : Start date for data scope (default: 6 months ago)}')]
#[Description('Generate Product Portfolio Overview PDF with DTC + B2B data')]
class GenerateProductOverviewCommand extends Command
{
    public function handle(ProductPortfolioReportService $reportService, AnalysisPdfService $pdf): int
    {
        try {
            $since = $this->option('since')
                ?? now()->subMonths(6)->startOfMonth()->toDateString();

            $this->info('Gathering data...');
            $data = $reportService->generate($since);

            $this->info('Rendering PDF...');
            $draftPath = $pdf->save($data, 'product-portfolio-overview_draft-1.pdf');
            $this->info("Draft saved: {$draftPath}");

            $paths = $pdf->finalize($draftPath, 'product-portfolio-overview.pdf');
            $this->info("Finalized: {$paths['desktop']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('GenerateProductOverviewCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
