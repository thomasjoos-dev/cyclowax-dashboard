<?php

namespace App\Services;

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
        $data['logo'] = storage_path('app/logos/cyclowax_logo_pdf.png');
        $data['date'] = $data['date'] ?? now()->format('d M Y');
        $data['metrics'] = $data['metrics'] ?? [];
        $data['sections'] = $data['sections'] ?? [];

        return Pdf::loadView('pdf.analysis', $data);
    }

    /**
     * Generate and save the PDF to a given path.
     */
    public function save(array $data, ?string $filename = null): string
    {
        $filename = $filename ?? 'analysis-'.now()->format('Y-m-d-His').'.pdf';
        $path = storage_path('app/analyses/'.$filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $this->generate($data)->save($path);

        return $path;
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
