<?php

namespace App\Console\Commands;

use App\Services\OdooClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('odoo:audit-shipping')]
#[Description('Audit Odoo shipping cost data: coverage per carrier, timeline, gaps')]
class OdooAuditShippingCommand extends Command
{
    public function handle(OdooClient $odoo): int
    {
        $this->info('Fetching outgoing pickings from Odoo...');

        $pickings = $odoo->searchRead(
            'stock.picking',
            [['picking_type_code', '=', 'outgoing'], ['carrier_id', '!=', false]],
            ['carrier_id', 'carrier_price', 'date_done', 'sale_id'],
        );

        $this->info('Pickings fetched: '.count($pickings));
        $this->newLine();

        // Per carrier: totaal, met cost, zonder cost
        $carriers = [];
        foreach ($pickings as $p) {
            $name = is_array($p['carrier_id']) ? $p['carrier_id'][1] : 'Unknown';
            $hasCost = $p['carrier_price'] > 0;

            if (! isset($carriers[$name])) {
                $carriers[$name] = ['total' => 0, 'with_cost' => 0, 'total_cost' => 0, 'earliest' => null, 'latest' => null];
            }

            $carriers[$name]['total']++;

            if ($hasCost) {
                $carriers[$name]['with_cost']++;
                $carriers[$name]['total_cost'] += $p['carrier_price'];
            }

            $date = $p['date_done'] ?? null;

            if ($date) {
                if (! $carriers[$name]['earliest'] || $date < $carriers[$name]['earliest']) {
                    $carriers[$name]['earliest'] = $date;
                }

                if (! $carriers[$name]['latest'] || $date > $carriers[$name]['latest']) {
                    $carriers[$name]['latest'] = $date;
                }
            }
        }

        // Sort by total desc
        uasort($carriers, fn ($a, $b) => $b['total'] <=> $a['total']);

        $this->info('CARRIER COVERAGE REPORT');
        $this->table(
            ['Carrier', 'Total', 'With Cost', 'Coverage', 'Avg Cost', 'Earliest', 'Latest'],
            collect($carriers)->map(fn ($c, $name) => [
                mb_substr($name, 0, 40),
                $c['total'],
                $c['with_cost'],
                $c['total'] > 0 ? round($c['with_cost'] / $c['total'] * 100).'%' : '0%',
                $c['with_cost'] > 0 ? '€'.number_format($c['total_cost'] / $c['with_cost'], 2) : '-',
                $c['earliest'] ? substr($c['earliest'], 0, 10) : '-',
                $c['latest'] ? substr($c['latest'], 0, 10) : '-',
            ])->values(),
        );

        // Summary
        $totalPickings = count($pickings);
        $withCost = collect($carriers)->sum('with_cost');
        $this->newLine();
        $this->info("Total: {$totalPickings} pickings, {$withCost} with cost (".round($withCost / $totalPickings * 100, 1).'%)');

        return self::SUCCESS;
    }
}
