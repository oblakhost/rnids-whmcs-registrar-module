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
        $certificatePassword = trim((string) ($moduleParams['epp_certificate_password'] ?? ''));

        $tls = [
            'allowSelfSigned' => $testmode,
            'caFilePath' => $moduleParams['epp_ca'] ?? '',
            'clientCertificatePath' => $moduleParams['epp_certificate'] ?? '',
            'verifyPeer' => !$testmode,
            'verifyPeerName' => !$testmode,
        ];

        if ($certificatePassword !== '') {
            $tls['clientCertificatePassword'] = $certificatePassword;
        }

        return [
            'host' => $testmode ? 'epp-test.rnids.rs' : 'epp.rnids.rs',
            'port' => 700,
            // Both RNIDS endpoints require hello and can omit clTRID in replies.
            // The SDK still rejects a present transaction ID that does not match.
            'greetingMode' => 'hello',
            'requireClientTransactionId' => false,
            'username' => $moduleParams['epp_username'] ?? '',
            'password' => $moduleParams['epp_password'] ?? '',
            'tls' => $tls,
        ];
    }
}
