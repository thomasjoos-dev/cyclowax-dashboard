<?php

namespace App\Services;

use App\Enums\LifecycleStage;
use App\Models\KlaviyoProfile;
use App\Models\RiderProfile;
use App\Models\ShopifyCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RiderProfileLinker
{
    public function __construct(
        protected SegmentTransitionLogger $transitionLogger,
    ) {}

    /**
     * Create and update unified rider profiles by matching email addresses.
     *
     * @return array{customers: int, followers: int}
     */
    public function link(): array
    {
        Log::info('Rider profile linking starting');

        $customers = $this->linkCustomers();
        $followers = $this->linkFollowers();

        Log::info('Rider profile linking completed', [
            'customers' => $customers,
            'followers' => $followers,
            'lifecycle_transitions' => $this->transitionLogger->loggedCount(),
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
                        'lifecycle_stage' => LifecycleStage::Customer->value,
                        'shopify_customer_id' => $customer->id,
                        'klaviyo_profile_id' => $klaviyoProfileId,
                        'linked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $emails = array_column($rows, 'email');

                $existingFollowers = RiderProfile::query()
                    ->whereIn('email', $emails)
                    ->where('lifecycle_stage', LifecycleStage::Follower->value)
                    ->pluck('segment', 'id')
                    ->toArray();

                DB::transaction(function () use ($rows) {
                    RiderProfile::query()->upsert($rows, ['email'], [
                        'lifecycle_stage',
                        'shopify_customer_id',
                        'klaviyo_profile_id',
                        'linked_at',
                        'updated_at',
                    ]);
                });

                if (! empty($existingFollowers)) {
                    foreach ($existingFollowers as $profileId => $lastSegment) {
                        $this->transitionLogger->logLifecycleChange(
                            $profileId,
                            LifecycleStage::Follower,
                            LifecycleStage::Customer,
                            $lastSegment,
                        );
                    }
                }

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
                RiderProfile::select('email')
            )
            ->chunkById(500, function ($profiles) use (&$count) {
                $rows = [];

                foreach ($profiles as $profile) {
                    $rows[] = [
                        'email' => strtolower($profile->email),
                        'lifecycle_stage' => LifecycleStage::Follower->value,
                        'shopify_customer_id' => null,
                        'klaviyo_profile_id' => $profile->id,
                        'linked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                DB::transaction(function () use ($rows) {
                    RiderProfile::query()->upsert($rows, ['email'], [
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
