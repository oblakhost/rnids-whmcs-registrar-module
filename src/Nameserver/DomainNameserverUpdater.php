<?php

declare(strict_types=1);

namespace Oblak\WHMCS\RSREG\Nameserver;

use RNIDS\Client;
use RNIDS\Exception\ProtocolExceptionFactory;
use RNIDS\Xml\NamespaceRegistry;
use RNIDS\Xml\Response\ResponseMetadataParser;
use RNIDS\Xml\XmlComposer;

final class DomainNameserverUpdater
{
    /**
     * @param list<string> $toAdd
     * @param list<string> $toRemove
     * @param array<string, array{ipv4:list<string>, ipv6:list<string>}> $knownHosts
     */
    public function rawDomainNameserverUpdate(
        Client $client,
        string $domainName,
        array $toAdd,
        array $toRemove,
        array $knownHosts,
    ): void {
        $clTrid = sprintf('RNIDS-NS-%d', random_int(100000, 999999));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<epp xmlns="' . NamespaceRegistry::EPP . '">'
            . '<command>'
            . '<update>'
            . '<domain:update xmlns:domain="' . NamespaceRegistry::DOMAIN . '">'
            . XmlComposer::element('domain:name', $domainName)
            . $this->domainNameserverSectionXml('domain:add', $toAdd, $knownHosts)
            . $this->domainNameserverSectionXml('domain:rem', $toRemove, $knownHosts)
            . '</domain:update>'
            . '</update>'
            . '<clTRID>' . XmlComposer::escape($clTrid) . '</clTRID>'
            . '</command>'
            . '</epp>';

        $transport = $client->transport();
        $transport->writeFrame($xml);
        $responseXml = $transport->readFrame();

        $metadata = (new ResponseMetadataParser())->parse($responseXml);
        if (!$metadata->isSuccess()) {
            throw ProtocolExceptionFactory::fromMetadata($metadata);
        }
    }

    /**
     * @param list<string> $nameservers
     * @param array<string, array{ipv4:list<string>, ipv6:list<string>}> $knownHosts
     */
    private function domainNameserverSectionXml(string $sectionNode, array $nameservers, array $knownHosts): string
    {
        if ($nameservers === []) {
            return '';
        }

        $nsXml = '';
        foreach ($nameservers as $nameserver) {
            $known = $knownHosts[$nameserver] ?? ['ipv4' => [], 'ipv6' => []];
            $addresses = array_merge($known['ipv4'], $known['ipv6']);

            if ($addresses === []) {
                $nsXml .= XmlComposer::element('domain:hostObj', $nameserver);
                continue;
            }

            $nsXml .= '<domain:hostAttr>'
                . XmlComposer::element('domain:hostName', $nameserver);

            foreach ($addresses as $address) {
                $nsXml .= XmlComposer::element('domain:hostAddr', $address);
            }

            $nsXml .= '</domain:hostAttr>';
        }

        return '<' . $sectionNode . '><domain:ns>' . $nsXml . '</domain:ns></' . $sectionNode . '>';
    }
}
