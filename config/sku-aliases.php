<?php

/**
 * Legacy Shopify SKU → Odoo SKU mapping.
 *
 * Older Shopify orders used numeric or non-standard SKUs.
 * This maps them to the correct Odoo product SKU.
 */
return [
    '100' => 'SK-WK12SEU',   // Waxing Kit
    '1000' => 'WX-PERW',     // Performance Wax Tablet
    '10' => 'QL-TF12S',      // Tool Free Quick Link 12 speed
];
