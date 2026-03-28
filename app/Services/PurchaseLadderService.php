<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PurchaseLadderService
{
    private string $since = '2024-01-01';

    /**
     * Customer cohort analysis by order count (1, 2, 3, 4, 5+).
     *
     * @return array<int, array{label: string, order_count: int, customers: int, avg_ltv: float, total_revenue: float}>
     */
    public function ladder(?string $since = null): array
    {
        $since ??= $this->since;

        $rows = DB::select("
            SELECT
                order_count,
                COUNT(*) as customers,
                ROUND(AVG(total_ltv), 0) as avg_ltv,
                ROUND(SUM(total_ltv), 0) as total_revenue
            FROM (
                SELECT
                    sc.id,
                    COUNT(so.id) as order_count,
                    SUM(so.net_revenue) as total_ltv
                FROM shopify_customers sc
                INNER JOIN shopify_orders so ON so.customer_id = sc.id
                WHERE so.ordered_at >= ?
                    AND so.financial_status NOT IN ('voided', 'refunded')
                GROUP BY sc.id
            )
            GROUP BY order_count
            ORDER BY order_count
        ", [$since]);

        $result = [];

        foreach ($rows as $row) {
            $oc = (int) $row->order_count;

            if ($oc <= 4) {
                $result[] = [
                    'label' => $oc.($oc === 1 ? ' order' : ' orders'),
                    'order_count' => $oc,
                    'customers' => (int) $row->customers,
                    'avg_ltv' => (float) $row->avg_ltv,
                    'total_revenue' => (float) $row->total_revenue,
                ];
            } else {
                if (isset($result[4])) {
                    $result[4]['customers'] += (int) $row->customers;
                    $result[4]['total_revenue'] += (float) $row->total_revenue;
                    $result[4]['avg_ltv'] = round($result[4]['total_revenue'] / $result[4]['customers']);
                } else {
                    $result[] = [
                        'label' => '5+ orders',
                        'order_count' => 5,
                        'customers' => (int) $row->customers,
                        'avg_ltv' => (float) $row->avg_ltv,
                        'total_revenue' => (float) $row->total_revenue,
                    ];
                }
            }
        }

        return $result;
    }
}
