<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\KlaviyoProfile;
use App\Models\ShopifyCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerProfileLinker
{
    /**
     * Create and update unified customer profiles by matching email addresses.
     *
     * @return array{customers: int, followers: int}
     */
    public function link(): array
    {
        Log::info('Customer profile linking starting');

        $customers = $this->linkCustomers();
        $followers = $this->linkFollowers();

        Log::info('Customer profile linking completed', [
            'customers' => $customers,
            'followers' => $followers,
        ]);

        return ['customers' => $customers, 'followers' => $followers];
    }

    /**
     * Upsert profiles for all Shopify customers, linking to Klaviyo where possible.
     */
    protected function linkCustomers(): int
    {
        $count = 0;

        ShopifyCustomer::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->chunkById(500, function ($customers) use (&$count) {
                $rows = [];

                foreach ($customers as $customer) {
                    $email = strtolower($customer->email);

                    $klaviyoProfileId = KlaviyoProfile::query()
                        ->whereRaw('LOWER(email) = ?', [$email])
                        ->value('id');

                    $rows[] = [
                        'email' => $email,
                        'lifecycle_stage' => 'customer',
                        'shopify_customer_id' => $customer->id,
                        'klaviyo_profile_id' => $klaviyoProfileId,
                        'linked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::transaction(function () use ($rows) {
                    CustomerProfile::query()->upsert($rows, ['email'], [
                        'lifecycle_stage',
                        'shopify_customer_id',
                        'klaviyo_profile_id',
                        'linked_at',
                        'updated_at',
                    ]);
                });

                $count += count($rows);
            });

        Log::info('Linked customer profiles', ['count' => $count]);

        return $count;
    }

    /**
     * Insert profiles for Klaviyo-only subscribers (followers).
     */
    protected function linkFollowers(): int
    {
        $count = 0;

        KlaviyoProfile::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereNotIn(
                DB::raw('LOWER(email)'),
                CustomerProfile::select('email')
            )
            ->chunkById(500, function ($profiles) use (&$count) {
                $rows = [];

                foreach ($profiles as $profile) {
                    $rows[] = [
                        'email' => strtolower($profile->email),
                        'lifecycle_stage' => 'follower',
                        'shopify_customer_id' => null,
                        'klaviyo_profile_id' => $profile->id,
                        'linked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::transaction(function () use ($rows) {
                    CustomerProfile::query()->upsert($rows, ['email'], [
                        'klaviyo_profile_id',
                        'linked_at',
                        'updated_at',
                    ]);
                });

                $count += count($rows);
            });

        Log::info('Linked follower profiles', ['count' => $count]);

        return $count;
    }
}
