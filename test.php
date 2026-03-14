<?php

use Oblak\WHMCS\RSREG\Model\InfoNormalizer;
use WHMCS\Invoice;

require_once __DIR__ . '/vendor/autoload.php';

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 3) . '/includes/registrarfunctions.php';
require_once __DIR__ . '/hooks.php';

$data = [
    ['domainid' => 3408,
        'registrar' => 'rnids',
        'sld' => 'selidbenovisadd',
        'tld' => 'rs',
    ],
    [
        'domainid' => 3423,
        'registrar' => 'rnids',
        'sld' => 'poney',
        'tld' => 'rs',
    ],
    
];

$inv = new Invoice(13317);


dump($inv->getData('datepaid'));
die;

$config = getRegistrarConfigOptions('rnids');

foreach ($data as $ddd) {
    $params = array_merge($config, $ddd);
    $params['regperiod'] = 1;

    dump(RegRenewDomain($params));
    die;
}


// loadRegistrarModule('rnids');

// rnids_GetDomainInformation(['sld' => 'example', 'tld' => 'com']);

// $data = json_decode($j, true);


