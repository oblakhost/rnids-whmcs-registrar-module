<?php

class Rnids_Hooks {

    public function __construct() {
        add_hook('ClientAreaPageDomainContacts', 1, $this->addCountriesVar(...));
        add_hook('DomainTransferCompleted', 1, $this->setContacts(...));
    }

    public function addCountriesVar($vars) {
        $vars['countries'] = (new WHMCS\Utility\Country())->getCountryNameArray();

        return $vars;
    }

    /**
     * 
     * Set the tech contact after transfer completion to the configured admin_id handle.
     * 
     * @param array{registrar: string, domainId: int, domain: string, registrationPeriod: string, expiryDate?: string} $vars Variables passed by the DomainTransferCompleted hook
     * @return void 
     */
    public function setContacts($vars): void {
        $registrar = strtolower(trim((string) ($vars['registrar'] ?? '')));
        if ($registrar !== 'rnids') {
            return;
        }

        $domain = strtolower(trim((string) ($vars['domain'] ?? '')));
        if (!$this->isSupportedTransferDomain($domain)) {
            return;
        }

        if (!loadRegistrarModule('rnids')) {
            logModuleCall(
                'rnids',
                'DomainTransferCompleted',
                [
                    'operation' => 'hook.transfer_completed.set_tech',
                    'domain' => $domain,
                ],
                [
                    'warning' => 'Unable to load registrar module rnids.',
                ]
            );
            return;
        }

        try {
            $config = getRegistrarConfigOptions('rnids');
            $configuredTechId = trim((string) ($config['admin_id'] ?? ''));
            if ($configuredTechId === '') {
                logModuleCall(
                    'rnids',
                    'DomainTransferCompleted',
                    [
                        'operation' => 'hook.transfer_completed.set_tech',
                        'domain' => $domain,
                    ],
                    [
                        'warning' => 'Missing admin_id in registrar configuration. Skipping tech contact update.',
                    ]
                );
                return;
            }

            $reg = rnids_App($config);
            $info = $reg->getInfo($domain);
            $currentTechId = trim((string) ($info['tech'] ?? ''));

            if ($currentTechId !== '' && strcasecmp($currentTechId, $configuredTechId) === 0) {
                logModuleCall(
                    'rnids',
                    'DomainTransferCompleted',
                    [
                        'operation' => 'hook.transfer_completed.set_tech',
                        'domain' => $domain,
                        'configuredTechId' => $configuredTechId,
                    ],
                    [
                        'noop' => true,
                        'reason' => 'Tech contact already matches configured admin_id.',
                    ]
                );
                return;
            }

            $payload = $this->buildTechContactUpdatePayload($domain, $configuredTechId, $currentTechId);
            $reg->client()->domain()->update($payload);

            logModuleCall(
                'rnids',
                'DomainTransferCompleted',
                [
                    'operation' => 'hook.transfer_completed.set_tech',
                    'domain' => $domain,
                    'configuredTechId' => $configuredTechId,
                    'previousTechId' => $currentTechId,
                ],
                [
                    'success' => true,
                ]
            );
        } catch (\Throwable $e) {
            logModuleCall(
                'rnids',
                'DomainTransferCompleted',
                [
                    'operation' => 'hook.transfer_completed.set_tech',
                    'domain' => $domain,
                ],
                [
                    'error' => [
                        'type' => get_class($e),
                        'message' => $e->getMessage(),
                    ],
                ]
            );
        }
    }

    /**
     * @return list<string>
     */
    private function transferSupportedTlds(): array {
        return [
            'rs',
            'in.rs',
            'co.rs',
            'org.rs',
            'edu.rs',
        ];
    }

    private function isSupportedTransferDomain(string $domain): bool {
        if ($domain === '') {
            return false;
        }

        foreach ($this->transferSupportedTlds() as $tld) {
            if ($domain === $tld || str_ends_with($domain, '.' . $tld)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildTechContactUpdatePayload(string $domain, string $configuredTechId, string $currentTechId): array {
        $payload = [
            'name' => $domain,
            'add' => [
                'contacts' => [
                    [
                        'type' => 'tech',
                        'handle' => $configuredTechId,
                    ],
                ],
            ],
        ];

        if ($currentTechId !== '' && strcasecmp($currentTechId, $configuredTechId) !== 0) {
            $payload['remove'] = [
                'contacts' => [
                    [
                        'type' => 'tech',
                        'handle' => $currentTechId,
                    ],
                ],
            ];
        }

        return $payload;
    }
   
}

new Rnids_Hooks();
