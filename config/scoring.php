<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Suspect Profile Detection
    |--------------------------------------------------------------------------
    |
    | Thresholds for flagging bot/spam profiles in profiles:flag-suspects.
    |
    */

    'suspect' => [
        'ghost_checkout_threshold' => 3,
        'ghost_checkout_max_views' => 0,
        'bot_open_multiplier' => 5,
        'email_patterns' => [
            '%example.com',
            '%mailinator%',
            '%guerrillamail%',
            '%tempmail%',
            '%disposable%',
            '%blackhat%',
            'guest@%',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Follower Engagement Scoring
    |--------------------------------------------------------------------------
    |
    | Weights, tiers and thresholds for the FollowerScorer service.
    |
    */

    'engagement' => [
        'weights' => [
            'site_visits' => 0.35,
            'email_clicks' => 0.30,
            'email_opens' => 0.20,
            'recency' => 0.15,
        ],

        'score_thresholds' => [
            5 => 0.60,
            4 => 0.40,
            3 => 0.25,
            2 => 0.10,
        ],

        'site_visit_tiers' => [
            11 => 1.0,
            6 => 0.8,
            3 => 0.6,
            1 => 0.3,
        ],

        'recency_days' => [
            7 => 1.0,
            30 => 0.7,
            90 => 0.3,
        ],

        'intent_decay_days' => 30,

        'intent_funnel' => [
            'checkout' => ['field' => 'checkouts_started', 'min' => 1, 'score' => 4],
            'cart' => ['field' => 'cart_adds', 'min' => 1, 'score' => 3],
            'product_view' => ['field' => 'product_views', 'min' => 2, 'score' => 2],
            'site_visit' => ['field' => 'site_visits', 'min' => 1, 'score' => 1],
        ],

        'segments' => [
            'hot_lead_min_intent' => 3,
            'hot_lead_max_days' => 30,
            'high_potential_min_intent' => 2,
            'high_potential_min_engagement' => 3,
            'high_potential_max_days' => 30,
            'new_max_days_since_signup' => 30,
            'engaged_min_engagement' => 3,
            'engaged_max_days' => 30,
            'fading_min_days' => 30,
            'fading_max_days' => 90,
            'fading_min_engagement' => 2,
            'fading_min_intent' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | RFM Customer Scoring
    |--------------------------------------------------------------------------
    |
    | Frequency breakpoints and segment waterfall rules for customers:calculate-rfm.
    | Custom F-breakpoints needed because 78% of customers have only 1 order.
    |
    */

    'rfm' => [
        'f_breakpoints' => [
            5 => 5,  // 5+ orders
            3 => 4,  // 3-4 orders
            2 => 3,  // 2 orders
            1 => 1,  // 1 order
        ],

        'segment_rules' => [
            'champion' => ['r' => '>= 4', 'f' => '>= 4', 'm' => '>= 4'],
            'at_risk' => ['r' => '<= 2', 'f' => '>= 3', 'm' => '>= 3'],
            'rising' => ['r' => '>= 3', 'f' => '>= 2', 'm' => '>= 3'],
            'loyal' => ['f' => '>= 3', 'm' => '>= 2'],
            'hunters' => ['f' => '>= 3', 'm' => '<= 2'],
            'promising_first' => ['f' => '= 1', 'm' => '>= 3'],
            'one_timer' => ['f' => '= 1', 'm' => '<= 2'],
        ],
    ],

];
