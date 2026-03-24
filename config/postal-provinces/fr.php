<?php

/**
 * France: postal code prefix (2 digits = departement) → Region code.
 *
 * Mapped to the 13 metropolitan regions + 5 overseas (DOM).
 * Source: La Poste / INSEE region codes.
 */
return [
    // Ile-de-France
    '75' => 'IDF', '77' => 'IDF', '78' => 'IDF',
    '91' => 'IDF', '92' => 'IDF', '93' => 'IDF', '94' => 'IDF', '95' => 'IDF',

    // Auvergne-Rhone-Alpes
    '01' => 'ARA', '03' => 'ARA', '07' => 'ARA', '15' => 'ARA',
    '26' => 'ARA', '38' => 'ARA', '42' => 'ARA', '43' => 'ARA',
    '63' => 'ARA', '69' => 'ARA', '73' => 'ARA', '74' => 'ARA',

    // Bourgogne-Franche-Comte
    '21' => 'BFC', '25' => 'BFC', '39' => 'BFC', '58' => 'BFC',
    '70' => 'BFC', '71' => 'BFC', '89' => 'BFC', '90' => 'BFC',

    // Bretagne
    '22' => 'BRE', '29' => 'BRE', '35' => 'BRE', '56' => 'BRE',

    // Centre-Val de Loire
    '18' => 'CVL', '28' => 'CVL', '36' => 'CVL', '37' => 'CVL',
    '41' => 'CVL', '45' => 'CVL',

    // Grand Est
    '08' => 'GES', '10' => 'GES', '51' => 'GES', '52' => 'GES',
    '54' => 'GES', '55' => 'GES', '57' => 'GES', '67' => 'GES', '68' => 'GES',
    '88' => 'GES',

    // Hauts-de-France
    '02' => 'HDF', '59' => 'HDF', '60' => 'HDF', '62' => 'HDF', '80' => 'HDF',

    // Normandie
    '14' => 'NOR', '27' => 'NOR', '50' => 'NOR', '61' => 'NOR', '76' => 'NOR',

    // Nouvelle-Aquitaine
    '16' => 'NAQ', '17' => 'NAQ', '19' => 'NAQ', '23' => 'NAQ',
    '24' => 'NAQ', '33' => 'NAQ', '40' => 'NAQ', '47' => 'NAQ',
    '64' => 'NAQ', '79' => 'NAQ', '86' => 'NAQ', '87' => 'NAQ',

    // Occitanie
    '09' => 'OCC', '11' => 'OCC', '12' => 'OCC', '30' => 'OCC',
    '31' => 'OCC', '32' => 'OCC', '34' => 'OCC', '46' => 'OCC',
    '48' => 'OCC', '65' => 'OCC', '66' => 'OCC', '81' => 'OCC', '82' => 'OCC',

    // Pays de la Loire
    '44' => 'PDL', '49' => 'PDL', '53' => 'PDL', '72' => 'PDL', '85' => 'PDL',

    // Provence-Alpes-Cote d'Azur
    '04' => 'PAC', '05' => 'PAC', '06' => 'PAC', '13' => 'PAC', '83' => 'PAC', '84' => 'PAC',

    // Corse
    '20' => 'COR',

    // DOM (overseas)
    '97' => 'DOM',
];
