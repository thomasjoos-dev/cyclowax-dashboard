<?php

/**
 * Estimated shipping costs per carrier.
 *
 * Based on actual carrier_price data from Odoo CW* orders (422 pickings).
 * Used as fallback when Odoo doesn't provide exact cost for Shopify orders.
 *
 * Key = normalized carrier name (lowercase, trimmed).
 * Value = estimated cost in EUR.
 */
return [
    'rates' => [
        // Belgian domestic
        'bpost' => 6.50,
        'bpost ->belgium' => 6.50,
        'bpost @ home' => 6.00,
        'bpost @ home 0-10kg (sendcloud)' => 5.88,

        // Bpack international
        'bpack @ world' => 9.72,
        'bpack world business 0-2kg (sendcloud)' => 7.82,
        'bpack world business 2-10kg (sendcloud)' => 9.29,
        'bpack world business 10-20kg (sendcloud)' => 12.38,
        'bpack world business 20-30kg (sendcloud)' => 10.58,

        // FedEx regional (EU)
        'fedex regional economy' => 15.00,
        'fedex ->germany' => 17.60,
        'fedex ->netherlands' => 14.97,
        'fedex ->austria' => 15.00,
        'fedex ->france' => 15.00,
        'fedex ->spain' => 25.67,
        'fedex ->italy' => 17.00,
        'fedex ->luxemburg' => 12.00,

        // FedEx international
        'fedex ->switzerland' => 92.73,
        'fedex ->uk' => 59.67,
        'fedex ->norway' => 64.75,
        'fedex ->dubai' => 45.00,
        'fedex economy' => 15.00,
        'fedex international economy®' => 20.00,
        'fedex international connect plus®' => 18.00,
        'fedex® regional economy' => 15.00,
        'fedex® regional economy - taxes & duties not included' => 15.00,

        // UPS
        'ups® ground' => 12.00,
        'ups® ground saver' => 10.00,
        'ups® standard' => 15.00,
        'ups worldwide express®' => 45.00,

        // USPS
        'usps ground shipping' => 8.00,
        'usps - priority mail' => 12.00,

        // Other
        'standard delivery' => 15.15,
        'sendbox xl swiss' => 35.00,

        // Free / no-cost carriers
        'free shipping' => 0,
        'volume discount' => 0,
        'pick-up' => 0,
    ],

    // Fallback per country group when carrier is unknown
    'fallback' => [
        'BE' => 6.50,
        'NL' => 10.00,
        'DE' => 15.00,
        'AT' => 15.00,
        'FR' => 15.00,
        'LU' => 12.00,
        'ES' => 20.00,
        'IT' => 17.00,
        'GB' => 40.00,
        'CH' => 60.00,
        'US' => 12.00,
        'CA' => 15.00,
        'AU' => 30.00,
        'default' => 15.00,
    ],
];
