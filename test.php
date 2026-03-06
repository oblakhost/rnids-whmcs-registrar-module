<?php

use Oblak\WHMCS\RSREG\Model\InfoNormalizer;

require_once __DIR__ . '/vendor/autoload.php';

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 3) . '/includes/registrarfunctions.php';

$data = [
    [
        'domainid' => 3423,
        'registrar' => 'rnids',
        'sld' => 'poney',
        'tld' => 'rs',
    ],
    ['domainid' => 3408,
        'registrar' => 'rnids',
        'sld' => 'selidbenovisadd',
        'tld' => 'rs',
    ]
];

$config = getRegistrarConfigOptions('rnids');

foreach ($data as $ddd) {
    $params = array_merge($config, $ddd);

    dump(rnids_GetContactDetails($params));
    die;
}


// loadRegistrarModule('rnids');

// rnids_GetDomainInformation(['sld' => 'example', 'tld' => 'com']);

// $data = json_decode($j, true);


