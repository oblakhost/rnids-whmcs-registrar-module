<?php

/**
 * RNIDS additional domain fields for WHMCS.
 *
 * Include this optional file from WHMCS resources/domains/additionalfields.php
 * to provide domain-specific overrides for the registrant's account details.
 *
 * @see https://docs.whmcs.com/9-0/domains/pricing-and-configuration/custom-domain-fields/
 */

// $additionaldomainfields = [];

$requiredWhenCompany = [
    'Registrant Type' => ['Company'],
];

$rs_ind_fields = [
    [
        'Name' => 'Registrant Type',
        'Type' => 'dropdown',
        'Options' => 'Individual,Company',
        'Default' => 'Individual',
        'Required' => true,
    ],
    [
        'Name' => 'Country',
        'Type' => 'dropdown',
        'Options' => '{CountryCodeMap}',
        'Required' => true,
    ],
    [
        'Name' => 'Company Name',
        'Type' => 'text',
        'Size' => '60',
        'Required' => $requiredWhenCompany,
    ],
    [
        'Name' => 'Company Number',
        'Type' => 'text',
        'Size' => '30',
        'Required' => false,
        'Description' => 'Leave blank to use the registrant VAT ID.',
    ],
    [
        'Name' => 'Tax Number',
        'Type' => 'text',
        'Size' => '30',
        'Required' => false,
        'Description' => 'Leave blank to use the registrant VAT ID.',
    ],
];

$rs_cmp_fields = [
    [
        'Name' => 'Registrant Type',
        'Type' => 'radio',
        'Options' => 'Company',
        'Default' => 'Company',
        'Required' => true,
    ],
    [
        'Name' => 'Country',
        'Type' => 'dropdown',
        'Options' => '{CountryCodeMap}',
        'Required' => true,
    ],
    [
        'Name' => 'Company Name',
        'Type' => 'text',
        'Size' => '60',
        'Required' => true,
    ],
    [
        'Name' => 'Company Number',
        'Type' => 'text',
        'Size' => '30',
        'Required' => false,
        'Description' => 'Leave blank to use the registrant VAT ID.',
    ],
    [
        'Name' => 'Tax Number',
        'Type' => 'text',
        'Size' => '30',
        'Required' => false,
        'Description' => 'Leave blank to use the registrant VAT ID.',
    ],
];

// TLDs that support both Individual and Company registrants.
foreach ([
    '.rs',
    '.in.rs',
    '.срб',
    '.од.срб',
] as $tld) {
    $additionaldomainfields[$tld] ??= [];
    foreach ($rs_ind_fields as $field) {
        $additionaldomainfields[$tld][] = $field;
    }
}

// Legal-entity-only TLDs (Company only).
foreach ([
    '.co.rs',
    '.org.rs',
    '.edu.rs',
    '.пр.срб',
    '.орг.срб',
    '.обр.срб',
] as $tld) {
    $additionaldomainfields[$tld] ??= [];
    foreach ($rs_cmp_fields as $field) {
        $additionaldomainfields[$tld][] = $field;
    }
}
