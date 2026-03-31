<?php

use App\Services\Support\PostalProvinceResolver;

beforeEach(function () {
    $this->resolver = new PostalProvinceResolver;
});

it('resolves German postal codes to Bundeslaender', function (string $postalCode, string $expected) {
    expect($this->resolver->resolve('DE', $postalCode))->toBe($expected);
})->with([
    'Berlin' => ['10115', 'BE'],
    'Bayern (Muenchen)' => ['80331', 'BY'],
    'Nordrhein-Westfalen (Koeln)' => ['50667', 'NW'],
    'Hamburg' => ['20095', 'HH'],
    'Sachsen (Dresden)' => ['01067', 'SN'],
]);

it('resolves Belgian postal codes to provinces', function (string $postalCode, string $expected) {
    expect($this->resolver->resolve('BE', $postalCode))->toBe($expected);
})->with([
    'Brussels' => ['1000', 'BRU'],
    'Antwerpen' => ['2000', 'VAN'],
    'Limburg (Hasselt)' => ['3500', 'VLI'],
    'Oost-Vlaanderen (Gent)' => ['9000', 'OVL'],
    'Liege' => ['4000', 'WLG'],
    'West-Vlaanderen (Brugge)' => ['8000', 'WVL'],
]);

it('resolves Dutch postal codes to provinces', function (string $postalCode, string $expected) {
    expect($this->resolver->resolve('NL', $postalCode))->toBe($expected);
})->with([
    'Amsterdam (Noord-Holland)' => ['1012', 'NH'],
    'Rotterdam (Zuid-Holland)' => ['3011', 'ZH'],
    'Utrecht' => ['3511', 'UT'],
    'Groningen' => ['9711', 'GR'],
    'Eindhoven (Noord-Brabant)' => ['5611', 'NB'],
]);

it('resolves Austrian postal codes to Bundeslaender', function (string $postalCode, string $expected) {
    expect($this->resolver->resolve('AT', $postalCode))->toBe($expected);
})->with([
    'Wien' => ['1010', '9'],
    'Salzburg' => ['5020', '5'],
    'Tirol (Innsbruck)' => ['6020', '7'],
]);

it('resolves Swiss postal codes to cantons', function (string $postalCode, string $expected) {
    expect($this->resolver->resolve('CH', $postalCode))->toBe($expected);
})->with([
    'Zuerich' => ['8001', 'ZH'],
    'Bern' => ['3011', 'BE'],
    'Geneve' => ['1201', 'GE'],
]);

it('resolves French postal codes to regions', function (string $postalCode, string $expected) {
    expect($this->resolver->resolve('FR', $postalCode))->toBe($expected);
})->with([
    'Paris (Ile-de-France)' => ['75001', 'IDF'],
    'Lyon (Auvergne-Rhone-Alpes)' => ['69001', 'ARA'],
    'Marseille (PACA)' => ['13001', 'PAC'],
]);

it('resolves Danish postal codes to regions', function (string $postalCode, string $expected) {
    expect($this->resolver->resolve('DK', $postalCode))->toBe($expected);
})->with([
    'Copenhagen (Hovedstaden)' => ['1050', 'DK-84'],
    'Aarhus (Midtjylland)' => ['8000', 'DK-82'],
    'Aalborg (Nordjylland)' => ['9000', 'DK-81'],
]);

it('resolves Swedish postal codes to laen', function (string $postalCode, string $expected) {
    expect($this->resolver->resolve('SE', $postalCode))->toBe($expected);
})->with([
    'Stockholm' => ['11120', 'AB'],
    'Goeteborg (Vaestra Goetaland)' => ['41101', 'O'],
]);

it('resolves Luxembourg postal codes to districts', function (string $postalCode, string $expected) {
    expect($this->resolver->resolve('LU', $postalCode))->toBe($expected);
})->with([
    'Luxembourg city' => ['1009', 'LU'],
    'Diekirch' => ['9200', 'DI'],
]);

it('returns null for unsupported country', function () {
    expect($this->resolver->resolve('US', '10001'))->toBeNull();
});

it('returns null for empty postal code', function () {
    expect($this->resolver->resolve('DE', ''))->toBeNull();
});

it('handles postal codes with spaces', function () {
    expect($this->resolver->resolve('SE', '111 20'))->toBe('AB');
    expect($this->resolver->resolve('NL', '10 12 AB'))->toBe('NH');
});
