<?php

add_hook('ClientAreaPageDomainContacts', 1, function($vars) {
    $vars['countries'] = (new WHMCS\Utility\Country())->getCountryNameArray();

    return $vars;
});