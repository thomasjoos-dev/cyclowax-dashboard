<?php

namespace App\Services\Analysis;

use App\Support\DbDialect;
use Illuminate\Support\Facades\DB;

class ChannelPerformanceService
{
    /**
     * Maps order refined_channel values to ad_spends platform.
     * Only paid channels with attributable spend are included.
     */
    private const CHANNEL_MAP = [
        'paid_google' => 'google_ads',
        'paid_instagram' => 'meta_ads',
        'paid_facebook' => 'meta_ads',
    ];

    /**
     * Total ad spend per platform for a period.
     *
     * @return array<string, array{platform: string, spend: float, impressions: int, clicks: int}>
     */
    public function spendByPlatform(string $from, string $to, ?string $countryCode = null): array
    {
        $params = [$from, $to];
        $countryWhere = '';

        if ($countryCode) {
            $countryWhere = ' AND country_code = ?';
            $params[] = $countryCode;
        }

        $rows = DB::select("
            SELECT
                platform,
                ROUND(SUM(spend), 2) as spend,
                SUM(impressions) as impressions,
                SUM(clicks) as clicks
            FROM ad_spends
            WHERE date >= ? AND date < ?{$countryWhere}
            GROUP BY platform
            ORDER BY spend DESC
        ", $params);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->platform] = [
                'platform' => $row->platform,
                'spend' => (float) $row->spend,
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
            ];
        }

        return $result;
    }

    /**
     * CAC (cost per acquired customer) per platform.
     *
     * @return array<string, array{platform: string, spend: float, first_orders: int, cac: float}>
     */
    public function cacByChannel(string $from, string $to, ?string $countryCode = null): array
    {
        $spend = $this->spendByPlatform($from, $to, $countryCode);
        $acquisitions = $this->firstOrdersByChannel($from, $to, $countryCode);

        $result = [];
        foreach (['google_ads', 'meta_ads'] as $platform) {
            $platformSpend = $spend[$platform]['spend'] ?? 0;
            $firstOrders = $acquisitions[$platform] ?? 0;

            $result[$platform] = [
                'platform' => $platform,
                'spend' => $platformSpend,
                'first_orders' => $firstOrders,
                'cac' => $firstOrders > 0 ? round($platformSpend / $firstOrders, 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * CAC per country across all paid platforms.
     *
     * @return array<int, array{country_code: string, spend: float, first_orders: int, cac: float}>
     */
    public function cacByCountry(string $from, string $to): array
    {
        $spendByCountry = DB::select("
            SELECT
                COALESCE(country_code, 'unknown') as country_code,
                ROUND(SUM(spend), 2) as spend
            FROM ad_spends
            WHERE date >= ? AND date < ?
            GROUP BY country_code
            ORDER BY spend DESC
        ", [$from, $to]);

        $ordersByCountry = DB::select("
            SELECT
                COALESCE(shipping_country_code, 'unknown') as country_code,
                COUNT(*) as first_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND is_first_order IS TRUE
                AND financial_status NOT IN ('voided', 'refunded')
                AND refined_channel IN ('paid_google', 'paid_instagram', 'paid_facebook')
            GROUP BY shipping_country_code
        ", [$from, $to]);

        $ordersMap = [];
        foreach ($ordersByCountry as $row) {
            $ordersMap[$row->country_code] = (int) $row->first_orders;
        }

        $result = [];
        foreach ($spendByCountry as $row) {
            $firstOrders = $ordersMap[$row->country_code] ?? 0;
            $result[] = [
                'country_code' => $row->country_code,
                'spend' => (float) $row->spend,
                'first_orders' => $firstOrders,
                'cac' => $firstOrders > 0 ? round((float) $row->spend / $firstOrders, 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * ROAS (return on ad spend) per platform.
     * Uses Shopify-side revenue attribution via refined_channel.
     *
     * @return array<string, array{platform: string, spend: float, attributed_revenue: float, roas: float}>
     */
    public function roasByChannel(string $from, string $to, ?string $countryCode = null): array
    {
        $spend = $this->spendByPlatform($from, $to, $countryCode);
        $revenue = $this->revenueByPlatform($from, $to, $countryCode);

        $result = [];
        foreach (['google_ads', 'meta_ads'] as $platform) {
            $platformSpend = $spend[$platform]['spend'] ?? 0;
            $platformRevenue = $revenue[$platform] ?? 0;

            $result[$platform] = [
                'platform' => $platform,
                'spend' => $platformSpend,
                'attributed_revenue' => $platformRevenue,
                'roas' => $platformSpend > 0 ? round($platformRevenue / $platformSpend, 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * Monthly CAC trend per platform.
     *
     * @return array<int, array{month: string, google_ads_cac: float, meta_ads_cac: float, blended_cac: float}>
     */
    public function channelEfficiencyTrend(int $months = 12): array
    {
        $since = now()->subMonths($months)->startOfMonth()->toDateString();
        $until = now()->addDay()->toDateString();

        $yearMonth = DbDialect::yearMonthExpr('date');
        $yearMonthOrdered = DbDialect::yearMonthExpr('ordered_at');

        $monthlySpend = DB::select("
            SELECT
                {$yearMonth} as month,
                platform,
                ROUND(SUM(spend), 2) as spend
            FROM ad_spends
            WHERE date >= ? AND date < ?
            GROUP BY month, platform
            ORDER BY month
        ", [$since, $until]);

        $monthlyOrders = DB::select("
            SELECT
                {$yearMonthOrdered} as month,
                refined_channel,
                COUNT(*) as first_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND is_first_order IS TRUE
                AND financial_status NOT IN ('voided', 'refunded')
                AND refined_channel IN ('paid_google', 'paid_instagram', 'paid_facebook')
            GROUP BY month, refined_channel
        ", [$since, $until]);

        // Build lookup maps
        $spendMap = [];
        foreach ($monthlySpend as $row) {
            $spendMap[$row->month][$row->platform] = (float) $row->spend;
        }

        $ordersMap = [];
        foreach ($monthlyOrders as $row) {
            $platform = self::CHANNEL_MAP[$row->refined_channel] ?? null;
            if ($platform) {
                $ordersMap[$row->month][$platform] = ($ordersMap[$row->month][$platform] ?? 0) + (int) $row->first_orders;
            }
        }

        $result = [];
        foreach ($spendMap as $month => $platforms) {
            $googleSpend = $platforms['google_ads'] ?? 0;
            $metaSpend = $platforms['meta_ads'] ?? 0;
            $googleOrders = $ordersMap[$month]['google_ads'] ?? 0;
            $metaOrders = $ordersMap[$month]['meta_ads'] ?? 0;
            $totalSpend = $googleSpend + $metaSpend;
            $totalOrders = $googleOrders + $metaOrders;

            $result[] = [
                'month' => $month,
                'google_ads_cac' => $googleOrders > 0 ? round($googleSpend / $googleOrders, 2) : 0,
                'meta_ads_cac' => $metaOrders > 0 ? round($metaSpend / $metaOrders, 2) : 0,
                'blended_cac' => $totalOrders > 0 ? round($totalSpend / $totalOrders, 2) : 0,
            ];
        }

        return $result;
    }

    /**
     * Channel mix: spend vs acquisitions vs revenue share per platform.
     *
     * @return array<string, array{platform: string, spend: float, spend_share: float, first_orders: int, acquisition_share: float, revenue: float, revenue_share: float}>
     */
    public function channelMix(string $from, string $to): array
    {
        $spend = $this->spendByPlatform($from, $to);
        $acquisitions = $this->firstOrdersByChannel($from, $to);
        $revenue = $this->revenueByPlatform($from, $to);

        $totalSpend = array_sum(array_column($spend, 'spend'));
        $totalOrders = array_sum($acquisitions);
        $totalRevenue = array_sum($revenue);

        $result = [];
        foreach (['google_ads', 'meta_ads'] as $platform) {
            $pSpend = $spend[$platform]['spend'] ?? 0;
            $pOrders = $acquisitions[$platform] ?? 0;
            $pRevenue = $revenue[$platform] ?? 0;

            $result[$platform] = [
                'platform' => $platform,
                'spend' => $pSpend,
                'spend_share' => $totalSpend > 0 ? round($pSpend * 100 / $totalSpend, 1) : 0,
                'first_orders' => $pOrders,
                'acquisition_share' => $totalOrders > 0 ? round($pOrders * 100 / $totalOrders, 1) : 0,
                'revenue' => $pRevenue,
                'revenue_share' => $totalRevenue > 0 ? round($pRevenue * 100 / $totalRevenue, 1) : 0,
            ];
        }

        return $result;
    }

    /**
     * Count first orders per platform via the channel crosswalk.
     *
     * @return array<string, int>
     */
    private function firstOrdersByChannel(string $from, string $to, ?string $countryCode = null): array
    {
        $params = [$from, $to];
        $countryWhere = '';

        if ($countryCode) {
            $countryWhere = ' AND shipping_country_code = ?';
            $params[] = $countryCode;
        }

        $rows = DB::select("
            SELECT
                refined_channel,
                COUNT(*) as first_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND is_first_order IS TRUE
                AND financial_status NOT IN ('voided', 'refunded')
                AND refined_channel IN ('paid_google', 'paid_instagram', 'paid_facebook')
                {$countryWhere}
            GROUP BY refined_channel
        ", $params);

        $result = ['google_ads' => 0, 'meta_ads' => 0];
        foreach ($rows as $row) {
            $platform = self::CHANNEL_MAP[$row->refined_channel] ?? null;
            if ($platform) {
                $result[$platform] += (int) $row->first_orders;
            }
        }

        return $result;
    }

    /**
     * Attributed revenue per platform (all orders from paid channels, not just first).
     *
     * @return array<string, float>
     */
    private function revenueByPlatform(string $from, string $to, ?string $countryCode = null): array
    {
        $params = [$from, $to];
        $countryWhere = '';

        if ($countryCode) {
            $countryWhere = ' AND shipping_country_code = ?';
            $params[] = $countryCode;
        }

        $rows = DB::select("
            SELECT
                refined_channel,
                ROUND(SUM(net_revenue), 2) as revenue
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND financial_status NOT IN ('voided', 'refunded')
                AND refined_channel IN ('paid_google', 'paid_instagram', 'paid_facebook')
                {$countryWhere}
            GROUP BY refined_channel
        ", $params);

        $result = ['google_ads' => 0.0, 'meta_ads' => 0.0];
        foreach ($rows as $row) {
            $platform = self::CHANNEL_MAP[$row->refined_channel] ?? null;
            if ($platform) {
                $result[$platform] += (float) $row->revenue;
            }
        }

        return $result;
    }
}
