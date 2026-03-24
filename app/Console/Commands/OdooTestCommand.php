<?php

namespace App\Console\Commands;

use App\Services\OdooClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('odoo:test')]
#[Description('Test the Odoo API connection and display sample product data')]
class OdooTestCommand extends Command
{
    public function handle(OdooClient $odoo): int
    {
        $this->info('Connecting to Odoo...');

        try {
            $uid = $odoo->authenticate();
            $this->info("Authenticated as uid: {$uid}");
        } catch (\Exception $e) {
            $this->error("Authentication failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Fetching products with SKU...');

        $products = $odoo->searchRead(
            'product.product',
            [['default_code', '!=', false]],
            ['default_code', 'name', 'standard_price', 'qty_available', 'virtual_available', 'uom_id'],
            limit: 10,
        );

        if (empty($products)) {
            $this->warn('No products with SKU found.');

            return self::SUCCESS;
        }

        $this->table(
            ['SKU', 'Name', 'COGS', 'On Hand', 'Forecasted', 'UoM'],
            collect($products)->map(fn (array $p) => [
                $p['default_code'],
                mb_substr($p['name'], 0, 40),
                number_format($p['standard_price'], 2),
                number_format($p['qty_available'], 1),
                number_format($p['virtual_available'], 1),
                is_array($p['uom_id']) ? $p['uom_id'][1] : $p['uom_id'],
            ]),
        );

        $totalProducts = $odoo->searchCount('product.product', [['default_code', '!=', false]]);
        $this->newLine();
        $this->info("Total products with SKU in Odoo: {$totalProducts}");

        return self::SUCCESS;
    }
}
