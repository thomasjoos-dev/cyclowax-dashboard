<?php

namespace App\Services\Sync;

use App\Models\AdSpend;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class AdSpendImporter
{
    /**
     * Country code mapping for campaign name parsing.
     *
     * @var array<string, string>
     */
    private const COUNTRY_MAP = [
        'DE' => 'DE',
        'BE' => 'BE',
        'NL' => 'NL',
        'US' => 'US',
        'USA' => 'US',
        'UK' => 'GB',
        'AT' => 'AT',
        'FR' => 'FR',
        'EU' => null,
        'BEN' => null, // Benelux
    ];

    private const UNIQUE_KEYS = ['date', 'platform', 'campaign_id', 'country_code'];

    private const UPDATE_COLUMNS = [
        'campaign_name', 'channel_type', 'spend', 'impressions',
        'clicks', 'conversions', 'conversions_value', 'updated_at',
    ];

    /**
     * Import ad spend data from an xlsx file using upsert (idempotent).
     *
     * @return array{google_ads: int, meta_ads: int}
     */
    public function import(string $filePath): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);

        $googleCount = $this->importGoogleAds($reader, $filePath);
        $metaCount = $this->importMetaAds($reader, $filePath);

        return [
            'google_ads' => $googleCount,
            'meta_ads' => $metaCount,
        ];
    }

    private function importGoogleAds(Xlsx $reader, string $filePath): int
    {
        $reader->setLoadSheetsOnly(['Data | GAds (Historical)']);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = [];
        $now = now();

        foreach ($sheet->getRowIterator(2) as $row) {
            $cells = [];
            foreach ($row->getCellIterator('A', 'N') as $cell) {
                $cells[] = $cell->getValue();
            }

            $date = $cells[0] ?? null;
            $campaignName = $cells[3] ?? null;

            if (! $date || ! $campaignName) {
                continue;
            }

            $date = $this->parseDate($date);

            if (! $date) {
                continue;
            }

            $campaignId = $this->cleanNumericId($cells[4]);
            $spend = $this->parseNumericValue($cells[6] ?? 0);

            if ($spend <= 0) {
                continue;
            }

            $rows[] = [
                'date' => $date,
                'platform' => 'google_ads',
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'country_code' => $this->parseCountryFromCampaign($campaignName),
                'channel_type' => $this->normalizeGoogleChannelType($cells[5] ?? ''),
                'spend' => round($spend, 2),
                'impressions' => (int) $this->parseNumericValue($cells[7] ?? 0),
                'clicks' => (int) $this->parseNumericValue($cells[8] ?? 0),
                'conversions' => round($this->parseNumericValue($cells[10] ?? 0), 2),
                'conversions_value' => round($this->parseNumericValue($cells[11] ?? 0), 2),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $spreadsheet->disconnectWorksheets();

        $this->upsertInChunks($rows, 'google_ads');

        return count($rows);
    }

    private function importMetaAds(Xlsx $reader, string $filePath): int
    {
        $reader->setLoadSheetsOnly(['Data | MetaAds (Historical)']);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = [];
        $now = now();

        foreach ($sheet->getRowIterator(2) as $row) {
            $cells = [];
            foreach ($row->getCellIterator('A', 'M') as $cell) {
                $cells[] = $cell->getValue();
            }

            $date = $cells[0] ?? null;
            $campaignName = $cells[3] ?? null;

            if (! $date || ! $campaignName) {
                continue;
            }

            $date = $this->parseDate($date);

            if (! $date) {
                continue;
            }

            $campaignId = $this->cleanNumericId($cells[4]);
            $spend = (float) ($cells[5] ?? 0);

            if ($spend <= 0) {
                continue;
            }

            $rows[] = [
                'date' => $date,
                'platform' => 'meta_ads',
                'campaign_id' => $campaignId,
                'campaign_name' => $campaignName,
                'country_code' => $this->parseCountryFromCampaign($campaignName),
                'channel_type' => $this->parseMetaCampaignType($campaignName),
                'spend' => round($spend, 2),
                'impressions' => (int) ($cells[7] ?? 0),
                'clicks' => (int) ($cells[8] ?? 0),
                'conversions' => round((float) ($cells[12] ?? 0), 2),
                'conversions_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $spreadsheet->disconnectWorksheets();

        $this->upsertInChunks($rows, 'meta_ads');

        return count($rows);
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // Excel serial date number (e.g. 45292.0 = 2024-01-01)
        if (is_numeric($value) && $value > 40000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($value))->format('Y-m-d');
            } catch (\Exception) {
                return null;
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse a cell value that may contain a simple division formula (e.g. "=10310000/1000000").
     */
    private function parseNumericValue(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $str = trim((string) $value);

        if (preg_match('/^=(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $str, $matches)) {
            return (float) $matches[1] / (float) $matches[2];
        }

        return 0;
    }

    private function cleanNumericId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return rtrim(rtrim((string) $value, '0'), '.');
    }

    /**
     * Parse country code from campaign name patterns:
     * - "DE | DE | Chain Wax" → DE
     * - "20250321_ad_25103-01_CYC_DE_SEM_Generic" → DE
     * - "2024 | ToFu | DE" → DE
     * - "20250506_ad_25103-01_cyc_uk_all_sales_always-on" → GB
     */
    private function parseCountryFromCampaign(string $name): ?string
    {
        // New naming convention: ..._CYC_XX_... or ..._cyc_xx_...
        if (preg_match('/[_](?:CYC|cyc)[_]([a-zA-Z]{2}(?:-[a-zA-Z0-9]+)?)[_]/i', $name, $matches)) {
            $code = strtoupper(explode('-', $matches[1])[0]);

            return self::COUNTRY_MAP[$code] ?? null;
        }

        // Pipe-separated: "DE | DE | Chain Wax" or "2024 | ToFu | DE"
        $parts = array_map('trim', explode('|', $name));

        foreach ($parts as $part) {
            $upper = strtoupper($part);

            if (isset(self::COUNTRY_MAP[$upper])) {
                return self::COUNTRY_MAP[$upper];
            }
        }

        // Dash-separated old format: "BE - NL - Generic - Wax"
        $parts = array_map('trim', explode('-', $name));

        foreach ($parts as $part) {
            $upper = strtoupper($part);

            if (isset(self::COUNTRY_MAP[$upper]) && self::COUNTRY_MAP[$upper] !== null) {
                return self::COUNTRY_MAP[$upper];
            }
        }

        return null;
    }

    private function normalizeGoogleChannelType(?string $type): ?string
    {
        return match (strtoupper(trim($type ?? ''))) {
            'SEARCH' => 'search',
            'SHOPPING' => 'shopping',
            'PERFORMANCE_MAX' => 'pmax',
            'DISPLAY' => 'display',
            'VIDEO' => 'video',
            default => null,
        };
    }

    /**
     * Derive campaign type from Meta campaign name:
     * - "dacq" or "ToFu" or "MoFu" or "Conversion" = acquisition
     * - "dret" or "Retargeting" = retargeting
     * - "tactical" keywords = tactical
     */
    private function parseMetaCampaignType(string $name): string
    {
        $lower = mb_strtolower($name);

        if (str_contains($lower, 'dret') || str_contains($lower, 'retargeting') || str_contains($lower, 'remarketing')) {
            return 'retargeting';
        }

        if (str_contains($lower, 'tactical')) {
            return 'tactical';
        }

        return 'acquisition';
    }

    /**
     * Upsert rows in chunks of 500 to avoid query size limits.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertInChunks(array $rows, string $platform): void
    {
        $skipped = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $withId = [];
            $withoutId = [];

            foreach ($chunk as $row) {
                if ($row['campaign_id'] !== null) {
                    $withId[] = $row;
                } else {
                    $withoutId[] = $row;
                    $skipped++;
                }
            }

            if ($withId) {
                AdSpend::upsert($withId, self::UNIQUE_KEYS, self::UPDATE_COLUMNS);
            }

            // Rows without campaign_id cannot match the unique index — insert individually
            foreach ($withoutId as $row) {
                AdSpend::query()->create($row);
            }
        }

        if ($skipped > 0) {
            Log::warning("AdSpend import: {$skipped} {$platform} rows without campaign_id — inserted without upsert");
        }
    }
}
