<?php

namespace App\Console\Commands;

use App\Services\Sync\AdSpendImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ads:import {file : Path to the xlsx file}')]
#[Description('Import ad spend data from the Ecommerce Performance Tracker xlsx')]
class ImportAdSpendCommand extends Command
{
    public function handle(AdSpendImporter $importer): int
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $this->info("Importing ad spend from: {$file}");

        $result = $importer->import($file);

        $this->info("  Google Ads: {$result['google_ads']} rows");
        $this->info("  Meta Ads: {$result['meta_ads']} rows");

        return self::SUCCESS;
    }
}
