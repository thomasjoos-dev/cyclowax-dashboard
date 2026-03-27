<?php

/**
 * Shopify product title → Odoo SKU mapping.
 *
 * For line items without SKU (bundles, legacy products, free items).
 * Keys are LOWERCASE exact Shopify product titles.
 * Values are the matching product SKU (Odoo or LOCAL-* for local-only products).
 *
 * LOCAL-* products exist only in our database, not in Odoo.
 */
return [
    // ─── Starter Kits ───
    'clean chain starter kit' => 'LOCAL-SK-CCSK',
    'clean chain starter kit (already payed, order #1662)' => 'LOCAL-SK-CCSK',
    'clean chain starter kit 8-speed' => 'LOCAL-SK-CCSK',
    'clean chain starter kit 9-speed campagnolo record' => 'LOCAL-SK-CCSK',
    'performance chain + starter kit (free shipping)' => 'LOCAL-SK-PCSK',

    // ─── Wax Kits ───
    'performance wax kit' => 'SK-PWK',
    'waxing kit' => 'SK-WK12SEU',
    'wax kit sea otter' => 'SK-WK12SEU',

    // ─── Wax Tablets ───
    'performance pro wax tablet' => 'WX-PERW',
    'performance pro wax tablet (discount)' => 'WX-PERW',
    'performance wax tablet' => 'WX-PERW',
    'clean chain performance wax tablet' => 'WX-PERW',
    'performance pro wax' => 'WX-PERW',
    'performance wax tablet' => 'WX-PERW',
    'basic wax tablet' => 'WX-BASIC',
    '400 gram basic wax' => 'WX-BASIC',
    'basic wax 500 gram' => 'WX-BASIC',
    'race wax' => 'WX-RC',
    'race wax tablet' => 'WX-RC',
    'race wax liquid' => 'WX-RC',
    'wax tablet' => 'WX-PERW',
    'new wax tablet' => 'WX-PERW',
    'start to wax' => 'WX-PERW',
    'stay waxed' => 'WX-PERW',
    'wax like a pro' => 'WX-PERW',
    'ho-ho-hot wax tablet' => 'WX-HOHO',
    'limited edition giro pink wax tablet' => 'WX-LEGP',
    'giro tablet' => 'WX-LEGP',

    // ─── Pocket Wax ───
    'pocket wax' => 'WX-POCK',
    'pocket wax (discount)' => 'WX-POCK',
    'core pocket wax' => 'WX-POCK',
    'performance pocket wax' => 'WX-PPOCK',
    'liquid wax' => 'WX-POCK',
    'liquid wax 30 ml' => 'WX-POCK',
    'liquid wax bottle 30 ml' => 'WX-POCK',
    'sample liquid wax' => 'WX-POCK',
    'liquid wax sample' => 'WX-POCK',

    // ─── Chains ───
    'cyclowax chain 11s' => 'CH-CW11S',
    'cyclowax chain 12s' => 'CH-CW12S',
    'cyclowax chain 10s' => 'CH-CW10S',
    'shimano ultegra 12s' => 'CH-UT12S',
    'shimano ultegra 11s' => 'CH-UT11S',
    'shimano ultegra 11 speed' => 'CH-UT11S',
    'shimano ultegra 11s' => 'CH-UT11S',
    'ultegra chain 11s' => 'CH-UT11S',
    'shimano dura-ace 12s' => 'CH-DA12S',
    'shimano dura-ace 11s' => 'CH-DA11S',
    'shimano dura-ace 11-speed' => 'CH-DA11S',
    'shimano dura-ace 11s chain' => 'CH-DA11S',
    'shimano  11 speed dura ace' => 'CH-DA11S',
    'shimano 11s pre-waxed chain' => 'CH-DA11S',
    'sram red flattop 12s' => 'CH-RF12S(E1)',
    'sram red e1 flattop 12s' => 'CH-RF12S(E1)',
    'sram red d1 flattop 12s' => 'CH-RF12S(E1)',
    'sram red e1 flattop 12s rainbow' => 'CH-RF12S(E1)RNBW',
    'sram force flattop 12s' => 'CH-SF12S',
    'sram gx eagle 12s' => 'CH-GX12S',
    'campagnolo super record 12s' => 'CH-CS12S',
    'campagnolo ekar 13s' => 'CH-CE13S',
    'campagnolo ekar 13s' => 'CH-CE13S',
    'zwift ride hot waxed chain' => 'CH-ZR01S',
    'ybn sla1210' => 'CH-YB12S',
    'ybn sla410 track chain' => 'CH-TC01S',
    'ybn sla410 race ready chain' => 'CH-TC01S',
    'ybn sla410 single speed' => 'CH-TC01S',
    'track chain, race-ready single speed' => 'CH-TC01S',
    'sram xx eagle transmission 12s' => 'CH-XE12S',
    'sram xx sl eagle transmission 12s' => 'CH-XS12S',
    'sram red axs 12s (legacy)' => 'CH-RF12S(E1)',
    'pre-waxed performance chain' => 'CH-CW12S',
    'free replacement chain' => 'CH-CW12S',

    // ─── Quick Links ───
    '12 speed quick link' => 'QL-SH12S',
    'sram 12 speed quick link' => 'QL-SA12S(SLVR)',
    'tool free quick link' => 'LOCAL-QL-TF12S',
    'tool free quick link 12speed' => 'LOCAL-QL-TF12S',
    'tool free quick link 11 speed' => 'LOCAL-QL-TF11S',
    'tool free quick link 10 speed' => 'LOCAL-QL-TF10S',
    'connex 12s quick link' => 'QL-CL12S',
    'connex link 12s (tool-free)' => 'QL-CL12S',
    'connex link 11s (tool-free)' => 'QL-CL11S',
    '11 speed quick link' => 'LOCAL-QL-11S',
    'campagnolo c-link 12s' => 'QL-CC12S',
    'sram powerlock axs red/force 12s' => 'QL-SA12S(SLVR)',
    'sram powerlock axs red/force 12s (rainbow)' => 'QL-SA12S(RNBW)',
    'ybn 12s (gold)' => 'QL-YBN12S(GLD)',

    // ─── Tools & Accessories ───
    'chain cutter' => 'TL-CC',
    'chain whip' => 'LOCAL-TL-CW',
    'swizzle wire' => 'TL-SW',
    'swizzle wire (for torben koehler 😉)' => 'TL-SW',
    'swizze wire - brass ring' => 'TL-SW',
    'bike preparation kit' => 'BK-KIT',
    'bike cleaning kit' => 'BK-KIT',
    'park tool chain checker cc-4' => 'TL-PTCC',
    'ybn quick link removal plier crp-101' => 'TL-QLRP',
    'ybn quick link installation plier clp-102' => 'TL-QLIP',
    'kmc quick link removal plier' => 'TL-MLRP',
    'hang-up tool' => 'TL-HUT',
    'hang-up tool' => 'TL-HUT',
    'hang up tool' => 'TL-HUT',
    'hang up tool' => 'TL-HUT',
    'hang-up tool' => 'TL-HUT',
    'chain hanger' => 'TL-HUT',
    'hanger' => 'TL-HUT',
    'protection mat' => 'TL-PM',
    'protective matt' => 'TL-PM',
    'protective matt' => 'TL-PM',

    // ─── Daysaver Multi-Tools ───
    'daysaver coworking5' => 'TL-DSC5',
    'daysaver essential8' => 'TL-DSE8',
    'daysaver guard' => 'TL-DSG',
    'daysaver carrier' => 'TL-DSC',

    // ─── Heaters ───
    'replacement heater, extended low cut-off time' => 'LOCAL-WH-REPL',
    'replacement heater' => 'LOCAL-WH-REPL',
    'replacement heater uk' => 'LOCAL-WH-REPL',
    'replacement wax heater' => 'LOCAL-WH-REPL',
    'replacement wax heater (220v)' => 'LOCAL-WH-REPL',
    'replacement hanger' => 'TL-HUT',
    'hot wax heater' => 'OEM-WH-EU',
    'wax heater' => 'OEM-WH-EU',
    'wax heater uk' => 'OEM-WH-UK',
    'us heater' => 'OEM-WH-US',
    'eu heater' => 'OEM-WH-EU',

    // ─── Gift Cards ───
    'gift card' => 'LOCAL-GIFT',
    'the clean chain experience under the christmas tree' => 'LOCAL-GIFT',

    // ─── Frame Stickers & Merchandise ───
    'cyclowax sticker (zwart & wit)' => 'MR-CFS',
    'cyclowax sticker' => 'MR-CFS',
    'cyclowax chain stay sticker' => 'MR-CFS',
    'cyclowax sticker set' => 'MR-CFS',
    'chainstay stickers' => 'MR-CFS',
    'frame sticker' => 'MR-CFS',
    'stickers' => 'MR-CFS',
    'stickers black + stickers white' => 'MR-CFS',
    'set stickers' => 'MR-CFS',
    'witte sticker' => 'MR-CFS',
];
