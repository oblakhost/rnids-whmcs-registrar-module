<?php

use Oblak\WHMCS\RSREG\Registrar;
use Oblak\WHMCS\RSREG\Support\ModuleLogger;
use RNIDS\Client;
use WHMCS\Domain\Registrar\Domain;
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Exception\Module\InvalidConfiguration;

require_once __DIR__ . '/vendor/autoload.php';


function rnids_MetaData(){
    return [
        'DisplayName' => 'RNIDS',
        'APIVersion' => '1.1',
    ];
}

function rnids_App(array $params): Registrar {
    return Registrar::fromParams($params);
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
                "Description" => "Check this to use RNIDS EPP test endpoint with relaxed TLS verification."
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
            'epp_certificate_password' => [
                'FriendlyName' => 'Certificate Password',
                'Type' => 'password',
                'Description' => 'Optional passphrase for the client certificate file.',
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
    $client = null;

    try {
        $registrar = rnids_App($params);
        $client = $registrar->client();
        $meta = $client->responseMeta();

        if (!is_array($meta) || !isset($meta['resultCode']) || (int) $meta['resultCode'] >= 2000) {
            throw new \RuntimeException('Remote registry health check failed.');
        }

        ModuleLogger::logModuleCall('ConfigValidate', $params, ['success' => true, 'responseMeta' => $meta]);

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
                'hasCertificatePassword' => !empty($params['epp_certificate_password']),
            ],
            ModuleLogger::exceptionContext($e)
        );
        throw new InvalidConfiguration(\Oblak\WHMCS\RSREG\Support\ErrorMessageFormatter::safeMessage(
            $e,
            'Connection test failed. Check the RNIDS credentials and certificate configuration.'
        ));
    } finally {
        if ($client instanceof Client) {
            try {
                $client->close();
            } catch (\Throwable $closeException) {
                ModuleLogger::logModuleCall(
                    'ConfigValidate',
                    [
                        'operation' => 'client.disconnect',
                    ],
                    [
                        'warning' => ModuleLogger::exceptionContext($closeException),
                    ]
                );
            }
        }
    }
}

/**
 * Check domain availability
 *
 * @param array{sld?: string, tlds?: array<int,string>, searchTerm?: string, punyCodeSearchTerm?: string, tldsToInclude?: array<int,string>, isIdnDomain?: bool, premiumEnabled?: bool} $params
 * @return \WHMCS\Domains\DomainLookup\ResultsList An ArrayObject based collection of \WHMCS\Domains\DomainLookup\SearchResult results
 */
function rnids_CheckAvailability($params): ResultsList {
    $registrar = rnids_App((array) $params);
    $tlds = isset($params['tlds']) && is_array($params['tlds'])
        ? array_values($params['tlds'])
        : (isset($params['tldsToInclude']) && is_array($params['tldsToInclude']) ? array_values($params['tldsToInclude']) : []);

    try {
        $results = $registrar->checkAvailabilityFromWhmcs((array) $params);

        ModuleLogger::logModuleCall(
            'CheckAvailability',
            [
                'operation' => 'domain.check',
                'sld' => (string) ($params['sld'] ?? ''),
                'searchTerm' => (string) ($params['searchTerm'] ?? ''),
                'punyCodeSearchTerm' => (string) ($params['punyCodeSearchTerm'] ?? ''),
                'tlds' => $tlds,
            ],
            [
                'success' => true,
                'resultCount' => $results->count(),
            ]
        );

        return $results;
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'CheckAvailability',
            [
                'operation' => 'domain.check',
                'sld' => (string) ($params['sld'] ?? ''),
                'searchTerm' => (string) ($params['searchTerm'] ?? ''),
                'punyCodeSearchTerm' => (string) ($params['punyCodeSearchTerm'] ?? ''),
                'tlds' => $tlds,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        throw new \RuntimeException(
            $registrar->safeErrorMessage($e, 'Unable to check domain availability'),
            0,
            $e
        );
    }
}

function rnids_RegisterDomain(array $params): array {
    $registrar = rnids_App($params);
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

function rnids_RenewDomain(array $params): array {
    $registrar = rnids_App($params);
    $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));

    try {
        $result = $registrar->renewDomain($params);

        ModuleLogger::logModuleCall(
            'RenewDomain',
            [
                'operation' => 'domain.renew',
                'domain' => $domainName,
                'period' => (int)($params['regperiod'] ?? 0),
            ],
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'RenewDomain',
            [
                'operation' => 'domain.renew',
                'domain' => $domainName,
                'period' => (int)($params['regperiod'] ?? 0),
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return [
            'error' => $registrar->safeErrorMessage($e, 'Unable to renew domain'),
        ];
    }
}

function rnids_TransferDomain(array $params): array {
    $registrar = rnids_App($params);
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
    $registrar = rnids_App($params);
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
    $registrar = rnids_App($params);
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



function rnids_GetDomainInformation($params) {
    $registrar = rnids_App($params);
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
    $registrar = rnids_App($params);

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
    $registrar = rnids_App($params);

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

function rnids_RegisterNameserver($params) {
    $registrar = rnids_App((array) $params);
    $logContext = rnids_buildNameserverHostLogContext((array) $params, 'host.create');

    try {
        $result = $registrar->registerNameserver((array) $params);

        ModuleLogger::logModuleCall(
            'RegisterNameserver',
            $logContext,
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'RegisterNameserver',
            $logContext,
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to register nameserver')];
    }
}

function rnids_ModifyNameserver($params) {
    $registrar = rnids_App((array) $params);
    $logContext = rnids_buildNameserverHostLogContext((array) $params, 'host.update');

    try {
        $result = $registrar->modifyNameserver((array) $params);

        ModuleLogger::logModuleCall(
            'ModifyNameserver',
            $logContext,
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'ModifyNameserver',
            $logContext,
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to modify nameserver')];
    }
}

function rnids_DeleteNameserver($params) {
    $registrar = rnids_App((array) $params);
    $logContext = rnids_buildNameserverHostLogContext((array) $params, 'host.delete');

    try {
        $result = $registrar->deleteNameserver((array) $params);

        ModuleLogger::logModuleCall(
            'DeleteNameserver',
            $logContext,
            [
                'success' => true,
                'response' => $result,
            ]
        );

        return $result;
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'DeleteNameserver',
            $logContext,
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to delete nameserver')];
    }
}

/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function rnids_buildNameserverHostLogContext(array $params, string $operation): array
{
    $sld = trim((string) ($params['sld'] ?? ''));
    $tld = ltrim(trim((string) ($params['tld'] ?? '')), '.');
    $domainName = strtolower(trim($sld . '.' . $tld, '.'));
    $requestedIp = trim((string) ($params['newipaddress'] ?? $params['ipaddress'] ?? ''));

    return [
        'operation' => $operation,
        'domain' => $domainName,
        'nameserver' => strtolower(rtrim(trim((string) ($params['nameserver'] ?? '')), '.')),
        'ipAddress' => $requestedIp,
        'currentIpAddress' => trim((string) ($params['currentipaddress'] ?? '')),
    ];
}

function rnids_GetRegistrarLock(array $params): array {
    $registrar = rnids_App($params);

    try {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));
        $result = $registrar->getRegistrarLockFromWhmcs($params);

        ModuleLogger::logModuleCall(
            'GetRegistrarLock',
            [
                'operation' => 'domain.info.lock',
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
            'GetRegistrarLock',
            [
                'operation' => 'domain.info.lock',
                'domain' => $domainName,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to fetch registrar lock state')];
    }
}

function rnids_SaveRegistrarLock(array $params): array {
    $registrar = rnids_App($params);

    try {
        $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));
        $result = $registrar->saveRegistrarLockFromWhmcs($params);

        ModuleLogger::logModuleCall(
            'SaveRegistrarLock',
            [
                'operation' => 'domain.update.lock',
                'domain' => $domainName,
                'requestedLock' => $params['lockenabled'] ?? null,
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
            'SaveRegistrarLock',
            [
                'operation' => 'domain.update.lock',
                'domain' => $domainName,
                'requestedLock' => $params['lockenabled'] ?? null,
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to update registrar lock state')];
    }
}

function rnids_GetContactDetails(array $params): array {
    $registrar = rnids_App($params);

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
    $registrar = rnids_App($params);

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
            ],
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return ['error' => $registrar->safeErrorMessage($e, 'Unable to save contact details')];
    }

}

function rnids_GetEPPCode(array $params): array {
    $reg = rnids_App($params);

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

function rnids_ResendIRTPVerificationEmail(array $params): array {
    $rsreg      = $params['testmode'] === 'on' ? 'rsreg2-test': 'rsreg';
    $domainName = strtolower(trim((string)($params['sld'] ?? '')) . '.' . ltrim(trim((string)($params['tld'] ?? '')), '.'));
    $domainName = trim($domainName, '.');
    $domainId = (int)($params['domainid'] ?? 0);
    $clientId = (int)($params['userid'] ?? 0);
    $requestedAt = date('c');
    $url = \App::getSystemUrl() . \App::get_admin_folder_name() . "/clientsdomains.php?userid=" . $clientId . "&id=" . $domainId;

    $requestContext = [
        'operation' => 'irtp.resend_verification_email.notify_contact',
        'domain' => $domainName,
        'userId' => isset($params['userid']) ? (int) $params['userid'] : null,
    ];

    try {

        $message = sprintf(
            <<<EMAIL
            Client has requested to resend the IRTP verification email for the following domain:<br><br>
            Domain: <a href="%s">%s</a><br>
            Domain ID: %s<br>
            Client ID: %s<br>
            Requested At: %s<br><br>

            You can check the request <a href="https://%s.rnids.rs/RsReg2/#/app/requests">here</a><br>
            EMAIL,
            $url,
            $domainName,
            $domainId,
            $clientId,
            $requestedAt,
            $rsreg,
        );


        sendAdminNotification('system', 'New IRTP Verification Email Request', $message);




        ModuleLogger::logModuleCall(
            'ResendIRTPVerificationEmail',
            $requestContext,
            [
                'success' => true,
                'response' => [
                    'delivered' => true,
                ],
            ]
        );

        return ['success' => true];
    } catch (\Throwable $e) {
        ModuleLogger::logModuleCall(
            'ResendIRTPVerificationEmail',
            $requestContext,
            [
                'error' => ModuleLogger::exceptionContext($e),
            ]
        );

        return [
            'error' => 'Unable to send IRTP resend notification email. Please contact support.',
        ];
    }
}
