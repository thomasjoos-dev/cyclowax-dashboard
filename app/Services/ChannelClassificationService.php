<?php

namespace App\Services;

use App\Models\ShopifyOrder;

class ChannelClassificationService
{
    /**
     * Derive the broad channel type (13 values) from order attribution data.
     */
    public function classifyChannelType(ShopifyOrder $order): ?string
    {
        $source = $order->ft_source;
        $sourceType = $order->ft_source_type;
        $utmMedium = $order->ft_utm_medium ? mb_strtolower($order->ft_utm_medium) : null;
        $sourceName = $order->source_name;

        if (! $source && ! $sourceName) {
            return null;
        }

        if (in_array($sourceName, ['shopify_draft_order', 'pos'])) {
            return $sourceName === 'pos' ? 'pos' : 'manual';
        }

        if (! $source) {
            return 'unknown';
        }

        $utmSource = $order->ft_utm_source ? mb_strtolower($order->ft_utm_source) : null;
        $isSocialSource = in_array($utmSource, ['ig', 'fb', 'facebook', 'instagram'])
            || str_starts_with($utmSource ?? '', 'instagram_')
            || str_starts_with($utmSource ?? '', 'facebook_');

        if ($utmMedium === 'cpc' && $isSocialSource) {
            return 'paid_social';
        }

        if ($utmMedium === 'cpc') {
            return 'paid_search';
        }

        if (in_array($utmMedium, ['social', 'paid', 'paidsocial'])) {
            return 'paid_social';
        }

        if ($utmMedium === 'email') {
            return 'email';
        }

        if ($utmMedium === 'product_sync') {
            return 'shopping_free';
        }

        $isAiReferral = str_contains($source, 'chatgpt.com') || str_contains($source, 'perplexity')
            || in_array($utmSource, ['chatgpt.com', 'perplexity', 'perplexity.ai']);

        if ($isAiReferral) {
            return 'ai_referral';
        }

        if ($sourceType === 'SEO') {
            return 'organic_search';
        }

        if ($source === 'direct') {
            return 'direct';
        }

        if (in_array($source, ['Google', 'Bing', 'DuckDuckGo', 'Yahoo'])) {
            return 'organic_search';
        }

        if (in_array($source, ['Instagram', 'Facebook'])) {
            return 'organic_social';
        }

        if ($source === 'email') {
            return 'email';
        }

        if (str_starts_with($source, 'http') || str_starts_with($source, 'android-app://')) {
            return 'referral';
        }

        return 'other';
    }

    /**
     * Derive the refined channel (16 values) from order attribution data.
     */
    public function classifyRefinedChannel(ShopifyOrder $order): ?string
    {
        $source = $order->ft_source;
        $sourceName = $order->source_name;
        $utmSource = $order->ft_utm_source ? mb_strtolower($order->ft_utm_source) : null;
        $utmMedium = $order->ft_utm_medium ? mb_strtolower($order->ft_utm_medium) : null;

        $isDraftOrder = $sourceName === 'shopify_draft_order';
        $isPaidMedium = in_array($utmMedium, ['cpc', 'paid', 'paidsocial', 'social']);

        $isInstagramSource = in_array($utmSource, ['ig', 'instagram'])
            || str_starts_with($utmSource ?? '', 'instagram_');

        $isFacebookSource = in_array($utmSource, ['fb', 'facebook'])
            || str_starts_with($utmSource ?? '', 'facebook_');

        // 1. Draft orders
        if ($isDraftOrder) {
            if ($utmSource && $isPaidMedium) {
                if ($isInstagramSource) {
                    return 'paid_instagram';
                }
                if ($isFacebookSource) {
                    return 'paid_facebook';
                }
                if ($utmSource === 'google') {
                    return 'paid_google';
                }
            }

            if ($utmMedium === 'email') {
                return 'email';
            }

            if ($utmMedium === 'product_sync') {
                return 'google_shopping_free';
            }

            if (! $utmSource && in_array($source, ['Google', 'Bing', 'DuckDuckGo', 'Yahoo'])) {
                return 'organic_google';
            }

            if (! $utmSource && in_array($source, ['Instagram', 'Facebook'])) {
                return $source === 'Instagram' ? 'organic_instagram' : 'organic_facebook';
            }

            if ($order->total_price == 0) {
                return 'manual_internal';
            }

            return 'manual_customer_service';
        }

        // 2. POS
        if ($sourceName === 'pos') {
            return 'pos';
        }

        // 3. No tracking data
        if (! $source && ! $sourceName) {
            return 'unknown';
        }

        if (! $source) {
            return 'unknown';
        }

        // 4. Paid channels (UTM-based)
        if ($isPaidMedium && $isInstagramSource) {
            return 'paid_instagram';
        }

        if ($isPaidMedium && $isFacebookSource) {
            return 'paid_facebook';
        }

        if ($utmMedium === 'cpc' && $utmSource === 'google') {
            return 'paid_google';
        }

        if ($utmMedium === 'cpc') {
            return 'paid_google';
        }

        // 5. Email
        if ($utmMedium === 'email') {
            return 'email';
        }

        // 6. Google Shopping (free)
        if ($utmMedium === 'product_sync') {
            return 'google_shopping_free';
        }

        // 7. AI referrals
        $isAiReferral = str_contains($source, 'chatgpt.com') || str_contains($source, 'perplexity')
            || in_array($utmSource, ['chatgpt.com', 'perplexity', 'perplexity.ai']);

        if ($isAiReferral) {
            return 'ai_referral';
        }

        // 8. Organic search
        if ($source === 'Google') {
            return 'organic_google';
        }

        if (in_array($source, ['Bing', 'DuckDuckGo', 'Yahoo'])) {
            return 'organic_bing';
        }

        if ($order->ft_source_type === 'SEO') {
            return 'organic_google';
        }

        // 9. Organic social
        if ($source === 'Instagram') {
            return 'organic_instagram';
        }

        if ($source === 'Facebook') {
            return 'organic_facebook';
        }

        // 10. Direct
        if ($source === 'direct') {
            return 'direct';
        }

        // 11. Email from source
        if ($source === 'email') {
            return 'email';
        }

        // 12. Referral (URLs as source)
        if (str_starts_with($source, 'http') || str_starts_with($source, 'android-app://')) {
            return 'referral';
        }

        return 'unknown';
    }

    /**
     * Classify channel_type for all orders that don't have one yet.
     */
    public function classifyUnclassifiedOrders(): int
    {
        $classified = 0;

        ShopifyOrder::query()
            ->whereNull('channel_type')
            ->chunkById(1000, function ($orders) use (&$classified) {
                foreach ($orders as $order) {
                    $channelType = $this->classifyChannelType($order);

                    if ($channelType) {
                        $order->update(['channel_type' => $channelType]);
                        $classified++;
                    }
                }
            });

        return $classified;
    }

    /**
     * Classify refined_channel for orders. When full=true, reclassifies all orders.
     */
    public function classifyRefinedChannels(bool $full = false): int
    {
        $classified = 0;

        $query = ShopifyOrder::query();

        if (! $full) {
            $query->whereNull('refined_channel');
        }

        $query->chunkById(1000, function ($orders) use (&$classified) {
            foreach ($orders as $order) {
                $refinedChannel = $this->classifyRefinedChannel($order);

                if ($refinedChannel) {
                    $order->update(['refined_channel' => $refinedChannel]);
                    $classified++;
                }
            }
        });

        return $classified;
    }
}
