<?php

namespace App\Console\Commands;

use App\Models\ShopifyCustomer;
use GenderDetector\GenderDetector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:detect-customer-gender {--force : Re-detect gender for all customers, not just those without gender}')]
#[Description('Detect gender for customers based on first name and country')]
class DetectCustomerGenderCommand extends Command
{
    private const GENDER_MAP = [
        'Male' => 'male',
        'MostlyMale' => 'male',
        'Female' => 'female',
        'MostlyFemale' => 'female',
        'Unisex' => 'unknown',
    ];

    private const PROBABILITY_MAP = [
        'Male' => 1.0,
        'MostlyMale' => 0.75,
        'Female' => 1.0,
        'MostlyFemale' => 0.75,
        'Unisex' => 0.5,
    ];

    public function handle(): int
    {
        try {
            $detector = new GenderDetector;

            $query = ShopifyCustomer::query()
                ->whereNotNull('first_name')
                ->where('first_name', '!=', '');

            if (! $this->option('force')) {
                $query->whereNull('gender');
            }

            $total = $query->count();

            if ($total === 0) {
                $this->info('No customers to process.');

                return self::SUCCESS;
            }

            $this->info("Processing {$total} customers...");

            $stats = ['male' => 0, 'female' => 0, 'unknown' => 0, 'undetected' => 0];

            $query->chunkById(500, function ($customers) use ($detector, &$stats) {
                foreach ($customers as $customer) {
                    $result = $detector->getGender(
                        $customer->first_name,
                        $customer->country_code,
                    );

                    if ($result === null) {
                        $customer->update([
                            'gender' => 'unknown',
                            'gender_probability' => 0,
                        ]);
                        $stats['undetected']++;

                        continue;
                    }

                    $genderName = $result->name;
                    $gender = self::GENDER_MAP[$genderName];
                    $probability = self::PROBABILITY_MAP[$genderName];

                    $customer->update([
                        'gender' => $gender,
                        'gender_probability' => $probability,
                    ]);

                    $stats[$gender]++;
                }
            });

            $this->table(
                ['Gender', 'Count', '%'],
                collect($stats)->map(fn ($count, $label) => [
                    ucfirst($label),
                    $count,
                    $total > 0 ? round($count / $total * 100, 1).'%' : '0%',
                ])->values()->toArray(),
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('DetectCustomerGenderCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
