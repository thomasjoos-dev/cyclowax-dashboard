<?php

namespace App\Services\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class AnalysisPdfService
{
    /**
     * Generate an analysis PDF and save it to storage.
     *
     * @param  array{
     *     title: string,
     *     subtitle?: string,
     *     context?: string,
     *     date?: string,
     *     metrics?: array<array{label: string, value: string, change?: string, direction?: string}>,
     *     sections: array<array{type: string, content?: string, headers?: array, rows?: array, items?: array}>
     * }  $data
     */
    public function generate(array $data): \Barryvdh\DomPDF\PDF
    {
        $data['logo'] = storage_path('app/visual-assets/cyclowax_logo_pdf.png');
        $data['date'] = $data['date'] ?? now()->format('d M Y');
        $data['metrics'] = $data['metrics'] ?? [];
        $data['sections'] = $data['sections'] ?? [];

        $pdf = Pdf::loadView('pdf.analysis', $data);

        if ($data['landscape'] ?? false) {
            $pdf->setPaper('a4', 'landscape');
        }

        return $pdf;
    }

    /**
     * Generate and save the PDF to a given path.
     */
    public function save(array $data, ?string $filename = null): string
    {
        $filename = $filename ?? 'analysis-'.now()->format('Y-m-d-His').'.pdf';
        $path = storage_path('app/data-analysis/drafts/'.$filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $this->generate($data)->save($path);

        return $path;
    }

    /**
     * Finalize a draft: copy to final-reports, Desktop, and clean up drafts.
     *
     * @return array{final: string, desktop: string}
     */
    public function finalize(string $draftPath, ?string $filename = null): array
    {
        $filename = $filename ?? basename($draftPath);
        $filename = str_replace(['_draft-', '_draft'], '', $filename);

        $finalDir = storage_path('app/data-analysis/final-reports');
        $desktopDir = config('analysis.output_path', ($_SERVER['HOME'] ?? '/tmp').'/Desktop');

        if (! is_dir($finalDir)) {
            mkdir($finalDir, 0755, true);
        }

        $finalPath = $finalDir.'/'.$filename;
        $desktopPath = $desktopDir.'/'.$filename;

        copy($draftPath, $finalPath);
        copy($draftPath, $desktopPath);

        $this->cleanupDrafts($draftPath);

        return [
            'final' => $finalPath,
            'desktop' => $desktopPath,
        ];
    }

    /**
     * Remove all draft versions related to a finalized report.
     */
    private function cleanupDrafts(string $draftPath): void
    {
        $draftsDir = storage_path('app/data-analysis/drafts');
        $basename = pathinfo($draftPath, PATHINFO_FILENAME);

        // Strip draft number suffix to find related drafts (e.g. rapport_draft-1, rapport_draft-2)
        $basePattern = preg_replace('/_draft-?\d*$/', '', $basename);

        foreach (glob($draftsDir.'/'.$basePattern.'*') as $file) {
            unlink($file);
        }
    }

    /**
     * Generate and return the PDF as a download response.
     */
    public function download(array $data, ?string $filename = null): Response
    {
        $filename = $filename ?? 'analysis-'.now()->format('Y-m-d-His').'.pdf';

        return $this->generate($data)->download($filename);
    }
}
