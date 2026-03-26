<?php

namespace Database\Factories;

use App\Models\KlaviyoCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KlaviyoCampaign>
 */
class KlaviyoCampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recipients = fake()->numberBetween(500, 10000);
        $delivered = (int) ($recipients * fake()->randomFloat(2, 0.90, 0.99));
        $opens = (int) ($delivered * fake()->randomFloat(2, 0.15, 0.60));
        $clicks = (int) ($opens * fake()->randomFloat(2, 0.05, 0.30));

        return [
            'klaviyo_id' => fake()->unique()->uuid(),
            'name' => fake()->sentence(3),
            'channel' => 'email',
            'status' => fake()->randomElement(['draft', 'scheduled', 'sent']),
            'archived' => false,
            'send_strategy' => 'immediate',
            'is_tracking_opens' => true,
            'is_tracking_clicks' => true,
            'recipients' => $recipients,
            'delivered' => $delivered,
            'bounced' => $recipients - $delivered,
            'opens' => $opens,
            'opens_unique' => (int) ($opens * 0.7),
            'clicks' => $clicks,
            'clicks_unique' => (int) ($clicks * 0.6),
            'unsubscribes' => fake()->numberBetween(0, 20),
            'conversions' => fake()->numberBetween(0, 50),
            'conversion_value' => fake()->randomFloat(2, 0, 2000),
            'revenue_per_recipient' => fake()->randomFloat(4, 0, 1),
            'scheduled_at' => fake()->dateTimeBetween('-6 months'),
            'send_time' => fake()->dateTimeBetween('-6 months'),
            'klaviyo_created_at' => fake()->dateTimeBetween('-1 year'),
            'klaviyo_updated_at' => fake()->dateTimeBetween('-6 months'),
        ];
    }

    /**
     * Campaign that has been sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
        ]);
    }
}
