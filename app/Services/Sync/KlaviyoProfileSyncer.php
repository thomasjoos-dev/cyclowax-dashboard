<?php

namespace App\Services\Sync;

use App\Models\KlaviyoProfile;
use App\Services\Api\KlaviyoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KlaviyoProfileSyncer
{
    protected int $syncedCount = 0;

    public function __construct(
        protected KlaviyoClient $klaviyo,
    ) {}

    /**
     * Sync profiles from Klaviyo, including predictive analytics.
     * When $since is provided, only profiles updated after that timestamp are fetched.
     */
    public function sync(?CarbonImmutable $since = null): int
    {
        $this->syncedCount = 0;

        Log::info('Klaviyo profile sync starting', ['incremental' => $since !== null]);

        $query = [
            'additional-fields[profile]' => 'predictive_analytics',
            'page[size]' => 100,
        ];

        if ($since) {
            $query['filter'] = "greater-than(updated,{$since->subMinutes(5)->toIso8601String()})";
        }

        $profiles = $this->klaviyo->paginate('profiles', $query);

        $batch = [];

        foreach ($profiles as $profile) {
            $batch[] = $profile;

            if (count($batch) >= 50) {
                $this->upsertBatch($batch);
                $batch = [];
            }
        }

        if (count($batch) > 0) {
            $this->upsertBatch($batch);
        }

        Log::info('Klaviyo profile sync completed', ['synced' => $this->syncedCount]);

        return $this->syncedCount;
    }

    /**
     * Upsert a batch of profiles into the database.
     *
     * @param  array<int, array<string, mixed>>  $batch
     */
    protected function upsertBatch(array $batch): void
    {
        $rows = array_map(fn (array $profile) => $this->mapProfile($profile), $batch);

        DB::transaction(function () use ($rows) {
            KlaviyoProfile::query()->upsert($rows, ['klaviyo_id'], [
                'email', 'phone_number', 'external_id', 'first_name', 'last_name',
                'organization', 'city', 'region', 'country', 'zip', 'timezone',
                'properties', 'historic_clv', 'predicted_clv', 'total_clv',
                'historic_number_of_orders', 'predicted_number_of_orders',
                'average_order_value', 'churn_probability',
                'average_days_between_orders', 'expected_date_of_next_order',
                'last_event_date', 'klaviyo_created_at', 'klaviyo_updated_at',
                'updated_at',
            ]);
        });

        $this->syncedCount += count($rows);

        Log::info('Klaviyo profiles batch upserted', ['batch_size' => count($rows), 'total' => $this->syncedCount]);
    }

    /**
     * Map a Klaviyo API profile to a database row.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    protected function mapProfile(array $profile): array
    {
        $attrs = $profile['attributes'] ?? [];
        $location = $attrs['location'] ?? [];
        $predictive = $attrs['predictive_analytics'] ?? [];

        return [
            'klaviyo_id' => $profile['id'],
            'email' => $attrs['email'] ?? null,
            'phone_number' => $attrs['phone_number'] ?? null,
            'external_id' => $attrs['external_id'] ?? null,
            'first_name' => $attrs['first_name'] ?? null,
            'last_name' => $attrs['last_name'] ?? null,
            'organization' => $attrs['organization'] ?? null,
            'city' => $location['city'] ?? null,
            'region' => $location['region'] ?? null,
            'country' => $location['country'] ?? null,
            'zip' => $location['zip'] ?? null,
            'timezone' => $location['timezone'] ?? null,
            'properties' => json_encode($attrs['properties'] ?? []),
            'historic_clv' => $predictive['historic_clv'] ?? null,
            'predicted_clv' => $predictive['predicted_clv'] ?? null,
            'total_clv' => $predictive['total_clv'] ?? null,
            'historic_number_of_orders' => $predictive['historic_number_of_orders'] ?? null,
            'predicted_number_of_orders' => $predictive['predicted_number_of_orders'] ?? null,
            'average_order_value' => $predictive['average_order_value'] ?? null,
            'churn_probability' => $predictive['churn_probability'] ?? null,
            'average_days_between_orders' => $predictive['average_days_between_orders'] ?? null,
            'expected_date_of_next_order' => $predictive['expected_date_of_next_order'] ?? null,
            'last_event_date' => $attrs['last_event_date'] ?? null,
            'klaviyo_created_at' => $attrs['created'] ?? null,
            'klaviyo_updated_at' => $attrs['updated'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
