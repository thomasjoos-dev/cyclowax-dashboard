<?php

namespace App\Console\Commands;

use App\Services\Sync\RiderProfileLinker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('profiles:link')]
#[Description('Create and update unified rider profiles by matching email addresses')]
class LinkRiderProfilesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RiderProfileLinker $linker): int
    {
        $this->components->info('Linking rider profiles...');

        try {
            $result = $linker->link();

            $this->components->info("Linked {$result['customers']} customers and {$result['followers']} followers.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Linking failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
