<?php

use Oblak\WHMCS\RSREG\Registrar;
use Oblak\WHMCS\RSREG\Support\ModuleLogger;
use RNIDS\Client;
use WHMCS\Domain\Registrar\Domain;
use WHMCS\Exception\Module\InvalidConfiguration;

require_once __DIR__ . '/vendor/autoload.php';

function rnids_MetaData(){
    return [
        'DisplayName' => 'RNIDS RsReg',
        'APIVersion' => '1.1',
    ];
}

function rnids_getConfigArray(): array{
    return [
        'FriendlyName' => [
                'Type' => 'System',
                'Value' => 'RNIDS',
            ],
            'Description' => [
               'Type' => 'System',
               'Value' => 'Register, renew and transfer .rs domains with a click of a button',
            ],
            "testmode" => [
                "FriendlyName" => "Test Mode",
                "Type" => "yesno",
                "Description" => "Check this to activate test mode. Will be implemented once EPP endpoints are fixed."
            ],
            "epp_username" => [
                "FriendlyName" => "User name",
                "Type" => "text",
                "Description" => "Your EPP user name."
            ],
            "epp_password" => [
                "FriendlyName" => "Password",
                "Type" => "password",
                "Description" => "Your EPP password."
            ],
            'epp_certificate' => [
                'FriendlyName' => 'Certificate',
                'Type' => 'text',
                'Description' => 'Path to the certificate file.',
                'Default' => '',
            ],
            'epp_ca' => [
                'FriendlyName' => 'CA',
                'Type' => 'text',
                'Description' => 'Path to the CA file.',
                'Default' => '',
            ],
            'reg_mb' => [
                'FriendlyName' => 'Registrant Company number ID',
                'Type' => 'text',
                'Description' => 'Enter the ID of the custom field',
                'Default' => '',
            ],

            'reg_pib' => [
                'FriendlyName' => 'Registrant Tax Number ID',
                'Type' => 'text',
                'Description' => 'Enter the ID of the custom field',
                'Default' => '',
            ],
            'admin_id' => [
                'FriendlyName' => 'Admin ID',
                'Type' => 'text',
                'Description' => 'Enter the RSreg ID',
                'Default' => '',
            ],
    ];
}

function rnids_config_validate($params) {
    try {
        Registrar::fromParams($params);

    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'ConfigValidate',
            [
                'operation' => 'client.connect',
                'testmode' => (($params['testmode'] ?? 'off') === 'on'),
                'hasUsername' => !empty($params['epp_username']),
                'hasPassword' => !empty($params['epp_password']),
                'hasCertificate' => !empty($params['epp_certificate']),
                'hasCaFile' => !empty($params['epp_ca']),
            ],
            ModuleLogger::exceptionContext($e)
        );
        throw new InvalidConfiguration('Connection test failed: ' . $e->getMessage());
    }
    // error_log(print_r($cl->responseMeta(), true));

    // $cl->close();
    // throw new InvalidConfiguration('This module is not yet ready for use. Please contact support for more information.');
}

function rnids_RegisterDomain(array $params): array {
    $registrar = Registrar::fromParams($params);
    $logContext = rnids_buildRegistrationLogContext($params);

    try {
        $result = $registrar->registerDomainFromWhmcs($params);

        ModuleLogger::logModuleCall(
            'RegisterDomain',
            $logContext,
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'RegisterDomain',
            $logContext,
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to register domain')];
    }
}

/**
 * @return array<string,mixed>
 */
function rnids_buildRegistrationLogContext(array $params): array
{
    $additionalFields = isset($params['additionalfields']) && is_array($params['additionalfields'])
        ? $params['additionalfields']
        : [];

    $contactDetails = isset($params['contactdetails']) && is_array($params['contactdetails'])
        ? $params['contactdetails']
        : [];

    $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));
    $requestNameservers = array_values(array_filter([
        strtolower(trim((string) ($params['ns1'] ?? ''))),
        strtolower(trim((string) ($params['ns2'] ?? ''))),
        strtolower(trim((string) ($params['ns3'] ?? ''))),
        strtolower(trim((string) ($params['ns4'] ?? ''))),
        strtolower(trim((string) ($params['ns5'] ?? ''))),
    ], static fn (string $ns): bool => $ns !== ''));

    return [
        'operation' => 'contact.create+host.sync+domain.register',
        'domain' => $domainName,
        'period' => (int)($params['regperiod'] ?? 0),
        'nameservers' => $requestNameservers,
        'validationContext' => [
            'hasRegistrantRoleInput' => isset($contactDetails['Registrant']) && is_array($contactDetails['Registrant']),
            'hasAdminRoleInput' => isset($contactDetails['Admin']) && is_array($contactDetails['Admin']),
            'hasTechRoleInput' => isset($contactDetails['Tech']) && is_array($contactDetails['Tech']),
            'hasBaseCompanyName' => trim((string)($params['companyname'] ?? '')) !== '',
            'hasBaseFirstname' => trim((string)($params['firstname'] ?? '')) !== '',
            'hasBaseLastname' => trim((string)($params['lastname'] ?? '')) !== '',
            'hasAdditionalCompanyName' => trim((string)($additionalFields['Company Name'] ?? '')) !== '',
            'hasAdditionalCompanyNumber' => trim((string)($additionalFields['Company Number'] ?? '')) !== '',
            'hasAdditionalTaxNumber' => trim((string)($additionalFields['Tax Number'] ?? '')) !== '',
            'hasCustomFields' => isset($params['customfields']) && is_array($params['customfields']) && $params['customfields'] !== [],
            'regMbMapping' => trim((string)($params['reg_mb'] ?? '')),
            'regPibMapping' => trim((string)($params['reg_pib'] ?? '')),
            'adminIdConfigured' => trim((string)($params['admin_id'] ?? '')) !== '',
        ],
    ];
}

function rnids_TransferDomain(array $params): array {
    $registrar = Registrar::fromParams($params);
    $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

    try {
        $result = $registrar->transferDomainFromWhmcs($params);

        ModuleLogger::logModuleCall(
            'TransferDomain',
            [
                'operation' => 'domain.transfer.approve',
                'domain' => $domainName,
                'hasAuthCode' => trim((string)($params['eppcode'] ?? $params['authcode'] ?? $params['authCode'] ?? $params['authinfo'] ?? $params['authInfo'] ?? '')) !== '',
            ],
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'TransferDomain',
            [
                'operation' => 'domain.transfer.approve',
                'domain' => $domainName,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to transfer domain')];
    }
}

function rnids_Sync(array $params): array
{
    $registrar = Registrar::fromParams($params);
    $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

    try {
        $result = $registrar->syncFromWhmcs($params);

        ModuleLogger::logModuleCall(
            'Sync',
            [
                'operation' => 'domain.sync',
                'domain' => $domainName,
            ],
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'Sync',
            [
                'operation' => 'domain.sync',
                'domain' => $domainName,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to sync domain status')];
    }
}

function rnids_TransferSync(array $params): array
{
    $registrar = Registrar::fromParams($params);
    $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

    try {
        $result = $registrar->transferSyncFromWhmcs($params);

        ModuleLogger::logModuleCall(
            'TransferSync',
            [
                'operation' => 'domain.transfer.sync',
                'domain' => $domainName,
            ],
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'TransferSync',
            [
                'operation' => 'domain.transfer.sync',
                'domain' => $domainName,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to sync transfer status')];
    }
}

function rnids_RenewDomain() {

}

function rnids_GetDomainInformation($params) {
    $registrar = Registrar::fromParams((array) $params);
    $client = null;

    try {
    $result = $registrar->getDomain($params);
    $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

    ModuleLogger::logModuleCall(
        'GetDomainInformation',
        [
            'operation' => 'domain.info',
            'domain' => $domainName,
        ],
        [
            'success' => true,
            'response' => $result,
        ]
    );

    return $result;


    } catch (\Throwable $e) {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

        ModuleLogger::logModuleCall(
            'GetDomainInformation',
            [
                'operation' => 'domain.info',
                'domain' => $domainName,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
                'meta' => [
                    'responseMeta' => $client instanceof Client ? $client->responseMeta() : null,
                ],
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to fetch domain information')];
    }


}

function rnids_GetNameservers(array $params): array {
    $registrar = Registrar::fromParams($params);

    try {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));
        $result = $registrar->getInfo($params)['nameservers'] ?? [];

        ModuleLogger::logModuleCall(
            'GetNameservers',
            [
                'operation' => 'domain.info.nameservers',
                'domain' => $domainName,
            ],
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

        ModuleLogger::logModuleCall(
            'GetNameservers',
            [
                'operation' => 'domain.info.nameservers',
                'domain' => $domainName,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to fetch nameservers')];
    }
}

function rnids_SaveNameservers(array $params): array {
    $registrar = Registrar::fromParams($params);

    try {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));
        $requestNameservers = array_values(array_filter([
            strtolower(trim((string) ($params['ns1'] ?? ''))),
            strtolower(trim((string) ($params['ns2'] ?? ''))),
            strtolower(trim((string) ($params['ns3'] ?? ''))),
            strtolower(trim((string) ($params['ns4'] ?? ''))),
            strtolower(trim((string) ($params['ns5'] ?? ''))),
        ], static fn (string $ns): bool => $ns !== ''));

        $result = $registrar->setNameServers($params);

        ModuleLogger::logModuleCall(
            'SaveNameservers',
            [
                'operation' => 'host.sync+domain.update.nameservers',
                'domain' => $domainName,
                'nameservers' => $requestNameservers,
            ],
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

        ModuleLogger::logModuleCall(
            'SaveNameservers',
            [
                'operation' => 'host.sync+domain.update.nameservers',
                'domain' => $domainName,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to save nameservers')];
    }
}

function rnids_GetRegistrarLock() {

}

function rnids_SaveRegistrarLock() {

}

function rnids_GetContactDetails(array $params): array {
    $registrar = Registrar::fromParams($params);

    try {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));
        $result = $registrar->getContactDetailsForWhmcs($params);

        ModuleLogger::logModuleCall(
            'GetContactDetails',
            [
                'operation' => 'contact.info',
                'domain' => $domainName,
            ],
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

        ModuleLogger::logModuleCall(
            'GetContactDetails',
            [
                'operation' => 'contact.info',
                'domain' => $domainName,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to fetch contact details')];
    }

}

function rnids_SaveContactDetails(array $params): array {
    $registrar = Registrar::fromParams($params);

    try {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));
        $result = $registrar->saveContactDetailsFromWhmcs($params);

        ModuleLogger::logModuleCall(
            'SaveContactDetails',
            [
                'operation' => 'contact.create+domain.update',
                'domain' => $domainName,
            ],
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

        ModuleLogger::logModuleCall(
            'SaveContactDetails',
            [
                'operation' => 'contact.create+domain.update',
                'domain' => $domainName,
                'params' => $params,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to save contact details')];
    }

}

function rnids_GetEPPCode(array $params): array {
    $reg = Registrar::fromParams($params);

    try {
        $result = $reg->getEPPCode($params);
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));
        $response = [
            'success' => true,
        ];

        ModuleLogger::logModuleCall(
            'GetEPPCode',
            [
                'operation' => 'domain.get_code',
                'domain' => $domainName,
            ],
            [
                'success' => true,
                'response' => [
                    'module' => $response,
                    'registry' => $result,
                ],
            ]
        );

        return $response;
    } catch (\Throwable $e) {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

        ModuleLogger::logModuleCall(
            'GetEPPCode',
            [
                'operation' => 'domain.get_code',
                'domain' => $domainName,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return [
            'error' => $reg->safeErrorMessage($e, 'Unable to request EPP code'),
        ];
    }
}

function rnids_ResendIRTPVerificationEmail() {

}