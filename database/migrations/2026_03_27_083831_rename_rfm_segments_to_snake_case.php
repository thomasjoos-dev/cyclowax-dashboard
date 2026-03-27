<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rename RFM segment values from PascalCase with spaces to snake_case.
     */
    public function up(): void
    {
        $renames = [
            'Top Customers' => 'champion',
            'At Risk' => 'at_risk',
            'High Potentials' => 'rising',
            'Loyal Middle' => 'loyal',
            'Bargain Hunters' => 'hunters',
            'Promising One-Timers' => 'promising_first',
            'Low-Value One-Timers' => 'one_timer',
            'Unclassified' => 'new_customer',
        ];

        foreach ($renames as $old => $new) {
            DB::table('shopify_customers')
                ->where('rfm_segment', $old)
                ->update(['rfm_segment' => $new]);
        }
    }

    /**
     * Reverse the segment renames.
     */
    public function down(): void
    {
        $renames = [
            'champion' => 'Top Customers',
            'at_risk' => 'At Risk',
            'rising' => 'High Potentials',
            'loyal' => 'Loyal Middle',
            'hunters' => 'Bargain Hunters',
            'promising_first' => 'Promising One-Timers',
            'one_timer' => 'Low-Value One-Timers',
            'new_customer' => 'Unclassified',
        ];

        foreach ($renames as $old => $new) {
            DB::table('shopify_customers')
                ->where('rfm_segment', $old)
                ->update(['rfm_segment' => $new]);
        }
    }
};
