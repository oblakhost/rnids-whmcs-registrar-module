<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Support;

final class ClientFactory
{
    /**
     * @param array<string,mixed> $moduleParams
     * @return array<string,mixed>
     */
    public static function buildClientParams(array $moduleParams): array
    {
        $testmode = ($moduleParams['testmode'] ?? 'off') === 'on';

        return [
            'host' => $testmode ? 'epp-test.rnids.rs' : 'epp.rnids.rs',
            'port' => 700,
            'username' => $moduleParams['epp_username'] ?? '',
            'password' => $moduleParams['epp_password'] ?? '',
            'tls' => [
                'allowSelfSigned' => true,
                'caFilePath' => $moduleParams['epp_ca'] ?? '',
                'clientCertificatePassword' => '12345',
                'clientCertificatePath' => $moduleParams['epp_certificate'] ?? '',
                'verifyPeer' => false,
                'verifyPeerName' => false,
            ],
        ];
    }
}
