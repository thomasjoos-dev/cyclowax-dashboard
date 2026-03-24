<?php

/**
 * Shopify product title → Odoo SKU mapping.
 *
 * For line items without SKU (bundles, legacy products, free items).
 * Keys are LOWERCASE exact Shopify product titles.
 * Values are the matching Odoo product SKU.
 *
 * Generated from analysis of 7,303 unmatched line items.
 * To be validated by product/operations team.
 */
return [
    // Starter Kits — map to the generic kit SKU (EU variant as default)
    'clean chain starter kit' => 'SK-PWK',
    'performance chain + starter kit (free shipping)' => 'SK-PWK',

    // Wax products
    'performance pro wax tablet' => 'WX-PERW',
    'performance pro wax tablet (discount)' => 'WX-PERW',
    'clean chain performance wax tablet' => 'WX-PERW',
    'performance wax tablet' => 'WX-PERW',
    'basic wax tablet' => 'WX-BASIC',
    'pocket wax' => 'WX-POCK',
    'pocket wax (discount)' => 'WX-POCK',

    // Wax Kits
    'performance wax kit' => 'SK-PWK',
    'waxing kit' => 'SK-WK12SEU',

    // Chains (loose, not in kit)
    'cyclowax chain 11s' => 'CH-CW11S',
    'cyclowax chain 12s' => 'CH-CW12S',
    'cyclowax chain 10s' => 'CH-CW10S',
    'shimano ultegra 12s' => 'CH-UT12S',
    'shimano dura-ace 12s' => 'CH-DA12S',
    'shimano ultegra 11s' => 'CH-UT11S',
    'sram red flattop 12s' => 'CH-RF12S(E1)',
    'sram force flattop 12s' => 'CH-SF12S',
    'sram gx eagle 12s' => 'CH-GX12S',
    'campagnolo super record 12s' => 'CH-CS12S',

    // Quick Links
    '12 speed quick link' => 'QL-SH12S',
    'sram 12 speed quick link' => 'QL-SA12S(SLVR)',
    'tool free quick link' => 'QL-TF12S',
    'tool free quick link 12speed' => 'QL-TF12S',
    'tool free quick link 11 speed' => 'QL-TF11S',

    // Tools & Accessories
    'chain cutter' => 'TL-CC',
    'swizzle wire' => 'TL-SW',
    'chain whip' => 'TL-CW',

    // Free / replacement items (map to base product for COGS approximation)
    'free replacement chain' => 'CH-CW12S',
];
