<?php //phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

use EppRegistrar\EPP\eppCheckRequest;
use EppRegistrar\EPP\eppCheckResponse;
use EppRegistrar\EPP\EppConnection;
use EppRegistrar\EPP\eppContact;
use EppRegistrar\EPP\eppContactHandle;
use EppRegistrar\EPP\eppContactPostalInfo;
use EppRegistrar\EPP\eppCreateHostRequest;
use EppRegistrar\EPP\eppCreateResponse;
use EppRegistrar\EPP\eppDomain;
use EppRegistrar\EPP\eppException;
use EppRegistrar\EPP\eppHelloRequest;
use EppRegistrar\EPP\eppHost;
use EppRegistrar\EPP\eppInfoContactRequest;
use EppRegistrar\EPP\eppInfoDomainRequest;
use EppRegistrar\EPP\eppInfoDomainResponse;
use EppRegistrar\EPP\eppLoginRequest;
use EppRegistrar\EPP\eppRenewRequest;
use EppRegistrar\EPP\eppTransferRequest;
use EppRegistrar\EPP\eppUpdateDomainRequest;
use EppRegistrar\EPP\eppUpdateDomainResponse;
use EppRegistrar\EPP\rnidsEppCreateContactRequest;
use EppRegistrar\EPP\rnidsEppCreateDomainRequest;
use EppRegistrar\EPP\rnidsEppInfoContactResponse;
use EppRegistrar\EPP\rnidsEppInfoDomainResponse;
use WHMCS\Carbon;
use WHMCS\Domain\Registrar\Domain;
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;

class RsRegBase
{
    /**
     * Domain status mapping
     *
     * @var array
     */
    private static $statuses = [
        'ok'       => Domain::STATUS_ACTIVE,
        'inactive' => Domain::STATUS_ACTIVE,
        'expired'  => Domain::STATUS_EXPIRED
    ];

    /**
     * Nameservers with IP addresses.
     *
     * @var array
     */
    private static $nameservers = [
        'ns1.oblak.host' => [
            '78.47.182.254' => 'v4',
            '2a01:4f8:1c17:6bcf::1' => 'v6,'
        ],
        'ns2.oblak.host' => [
            '78.47.152.85' => 'v4',
            '2a01:4f8:c2c:cf73::1' => 'v6,'
        ],
        'ns3.oblak.host' => [
            '95.217.20.196' => 'v4',
            '2a01:4f9:c010:6486::1' => 'v6,'
        ],
        'ns4.oblak.host' => [
            '88.99.120.143' => 'v4',
            '2a01:4f8:c010:4712::1' => 'v6,'
        ],
        'ns5.oblak.host' => [
            '78.47.182.254' => 'v4',
            '2a01:4f8:1c17:6bcf::1' => 'v6,'
        ],
        'ns6.oblak.host' => [
            '95.217.20.196' => 'v4',
            '2a01:4f9:c010:6486::1' => 'v6,'
        ],
        'ns7.oblak.host' => [
            '78.47.152.85' => 'v4',
            '2a01:4f8:c2c:cf73::1' => 'v6,'
        ],
        'ns8.oblak.host' => [
            '88.99.120.143' => 'v4',
            '2a01:4f8:c010:4712::1' => 'v6,'
        ],
    ];

    /**
     * Epp connection client
     *
     * @var EppConnection
     */
    private $client = null;

    /**
     * Epp connection status
     *
     * @var bool
     */
    private static $logged_in = false;

    /**
     * Class constructor
     *
     * @param aarray $params Module parameters
     */
    public function __construct($params)
    {
        $this->client = $this->getEppConnection($params);
    }

    /**
     * Get module config
     *
     * @return array Module config
     */
    public static function getConfig()
    {
        return array(
            'FriendlyName' => [
                'Type' => 'System',
                'Value' => 'RsReg',
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
            "epp_hostname" => [
                "FriendlyName" => "Host name",
                "Type" => "text",
                "Description" => "Change this to the address of the EPP server.",
                "Default" => "epp.rnids.rs"
            ],
            "epp_port" => [
                "FriendlyName" => "Port number",
                "Type" => "text",
                "Description" => "Change this to the port of the EPP server.",
                "Default" => "700"
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
        );
    }

    /**
     * Create EPP connection
     *
     * @param  array $params Module parameters
     * @return EppConnection
     *
     * @throws Exception When connection cannot be established
     */
    private function getEppConnection($params)
    {

        //Instantiate the connection.
        $client = new \EppRegistrar\EPP\EppConnection();

        // Set the params.
        $client->setHostname($params['epp_hostname']);
        $client->setUsername($params['epp_username']);
        $client->setPassword($params['epp_password']);
        $client->setPort($params['epp_port']);

        // Enable certification.
        $client->enableCertification($params['epp_certificate'], 12345, $params['epp_ca'], '*.rnids.rs');

        // Add extensions.
        $client->addExtension('domain-rnids-ext', 'http://www.rnids.rs/epp/xml/rnids-1.0');
        $client->addExtension('contact-rnids-ext', 'http://www.rnids.rs/epp/xml/rnids-1.0');

        // Add command responses.
        $client->addCommandResponse(
            'EppRegistrar\EPP\rnidsEppCreateContactRequest',
            'EppRegistrar\EPP\eppCreateResponse'
        );
        $client->addCommandResponse(
            'EppRegistrar\EPP\eppInfoContactRequest',
            'EppRegistrar\EPP\rnidsEppInfoContactResponse'
        );
        $client->addCommandResponse(
            'EppRegistrar\EPP\rnidsEppUpdateContactRequest',
            'EppRegistrar\EPP\eppUpdateContactResponse'
        );

        $client->connect();

        $request = new eppHelloRequest();
        $client->writeandread($request);

        return $client;
    }

    /**
     * Login to EPP system
     *
     * @return true on success
     *
     * @throws Exception When login fails
     */
    private function eppLogin()
    {
        $request = new eppLoginRequest();
        $response = $this->client->writeandread($request);

        if (!$response->Success()) {
            throw new Exception("Login failed");
        }

        return true;
    }

    /**
     * Retrieve the domain object
     *
     * @param  string $domain    Domain name.
     * @param  string $call_type Type of call.
     * @return rnidsEppInfoDomainResponse Domain object
     */
    private function getDomain($domain, $call_type = 'Info')
    {
        if (!self::$logged_in) {
            $this->eppLogin();
        }

        $d_obj = new eppDomain($domain);
        $info  = new eppInfoDomainRequest($d_obj);

         /**
         * Response override
         *
         * @var eppInfoDomainResponse $response
         */
        $response = $this->client->writeandread($info);

        logModuleCall('RsReg', $call_type, $info->saveXML(), $response->saveXML());

        if (!$response->Success() || $response->getResultCode() != '1000') {
            throw new Exception("Failed to get domain info");
        }

        return $response;
    }

    public function getDomainInfo($domain)
    {
        $d_obj = $this->getDomain($domain);

        $expiry_date = Carbon::createFromFormat(
            'Y-m-d G:i:s',
            str_replace('T', ' ', $d_obj->getDomainExpirationDate())
        );
        $today       = Carbon::today();
        $grace_date  = $expiry_date->copy()->addDays(30);


        $response = [
            'domain'         => $domain,
            'nameservers'    => $this->getNameservers($d_obj),
            'status'         => self::$statuses[$d_obj->getDomainStatuses()[0]],
            'expirydate'     => $expiry_date,
            'restorable'     => $grace_date > $today,
            'pendingsuspend' => in_array('pendingUpdate', $d_obj->getDomainStatuses()),
            'pendingcontact' => in_array('pendingUpdate', $d_obj->getDomainStatuses()),
            'whoisguard'     => $d_obj->getWhoisPrivacy(),
        ];

        return $response;
    }

    public function register($params)
    {
        if (!self::$logged_in) {
            $this->eppLogin();
        }

        $nameservers = [];
        for ($i = 1; $i <= 5; $i++) {
            $ns = $params['ns' . $i];
            if (!empty($ns)) {
                $nameservers[] = $ns;
            }
        }

        foreach ($nameservers as $ns) {
            if ($this->checkNameservers($ns)) {
                $this->createNameserver($ns);
            }
        }

        $contacts = [
            'registrant' => $this->getContactInfo($params, 'registrant'),
            'tech'       => $this->getContactInfo($params, 'tech'),
        ];
        $contacts['admin'] = $contacts['registrant'];

        if (in_array(false, array_values($contacts), true)) {
            throw new Exception("Failed to create contacts");
        }

        $domain_name = $params['sld'] . '.' . $params['tld'];

        $registrant = new eppContactHandle($contacts['registrant'], eppContactHandle::CONTACT_TYPE_REGISTRANT);
        $admin      = new eppContactHandle($contacts['admin'], eppContactHandle::CONTACT_TYPE_ADMIN);
        $tech       = new eppContactHandle($contacts['tech'], eppContactHandle::CONTACT_TYPE_TECH);
        $hosts      = [];

        foreach (array_unique($nameservers) as $ns) {
            $ip_address = array_key_exists($ns, self::$nameservers) ? array_flip(self::$nameservers[$ns]) : null;
            $hosts[]    = new eppHost($ns, ['v4' => gethostbyname($ns)]);
        }

        $domain = new eppDomain(
            $domain_name,
            $registrant,
            [$admin, $tech],
            $hosts,
            $params['regperiod'],
            $this->uuidv4()
        );
        $domain->setPeriodUnit('y');

        $request = new rnidsEppCreateDomainRequest($domain, true, 'Powered by Oblak', $params['idprotection'], 'normal', false, false);

        $response = $this->client->writeandread($request);

        logModuleCall('RsReg', 'Create domain', $request->saveXML(), $response->saveXML());

        return $response->Success();
    }

    public function transfer($params)
    {
        $domain = "{$params['sld']}.{$params['tld']}";
        $eppcode = $params['eppcode'];

        if (!self::$logged_in) {
            $this->eppLogin();
        }

        $d_obj = new eppDomain($domain);
        $d_obj->setAuthorisationCode($eppcode);

        $request = new eppTransferRequest(eppTransferRequest::OPERATION_APPROVE, $d_obj);
        $response = $this->client->writeandread($request);

        logModuleCall('RsReg', 'Transfer domain', $request->saveXML(), $response->saveXML());

        if (!$response->Success()) {
            throw new Exception("Failed to transfer domain");
        }

        return [
            'success' => true,
        ];
    }

    public function renew($params)
    {
        $domain = "{$params['sld']}.{$params['tld']}";
        $d_obj  = $this->getDomain($domain, 'Renew');
        $r_obj = new eppDomain($domain);
        $r_obj->setPeriodUnit('y');
        $r_obj->setPeriod($params['regperiod']);

        $expiry = date('Y-m-d', strtotime($d_obj->getDomainExpirationDate()));

        $req = new eppRenewRequest($r_obj, $expiry);
        $res = $this->client->writeandread($req);

        if (!$res->Success()) {
            throw new Exception("Failed to renew domain");
        }

        return true;
    }

    public function getTransferCode($domain)
    {
        if (!self::$logged_in) {
            $this->eppLogin();
        }

        $d_obj = new eppDomain($domain);
        $request = new eppTransferRequest(eppTransferRequest::OPERATION_REQUEST, $d_obj);

        $response = $this->client->writeandread($request);

        logModuleCall('RsReg', 'Get EPP code', $request->saveXML(), $response->saveXML());

        if (!$response->Success()) {
            throw new Exception("Failed to get transfer code");
        }
    }

    public function checkAvailability($params)
    {
        if (!self::$logged_in) {
            $this->eppLogin();
        }

        $domain = "{$params['sld']}.{$params['tld']}";

        $dtc = new eppCheckRequest([$domain]);

        /**
         * Response variable override
         *
         * @var eppCheckResponse $response
         */
        $response = $this->client->writeandread($dtc);
        $results = new ResultsList();

        logModuleCall('RsReg', 'Check availability', $dtc->saveXML(), $response->saveXML());

        foreach ($response->getCheckedDomains() as $check) {
            $result = $check['available'];

            error_log($domain . ' is ' . ($result ? 'available' : 'not available'));

            $search_result = new SearchResult($params['sld'], $params['tld']);

            if ($result) {
                $search_result->setStatus(SearchResult::STATUS_NOT_REGISTERED);
            } else {
                $search_result->setStatus(SearchResult::STATUS_REGISTERED);
            }

            $results->append($search_result);
        }

        return $results;
    }

    public function getSuggestions()
    {
        $results = new ResultsList();

        return $results;
    }

    /**
     * Get nameservers for domain
     *
     * @param  string $domain Domain name
     * @return array          Nameservers array
     * @throws Exception      When domain does not exist
     * @throws eppException   When EPP error occurs
     */
    public function getNameservers($domain)
    {
        $response = $domain instanceof eppInfoDomainResponse
            ? $domain
            : $this->getDomain($domain);
        // $response = $this->getDomain($domain);

        $data = [];
        $d    = $response->getDomain();
        $i    = 1;

        foreach ($d->getHosts() as $ns) {
            $data['ns' . $i] = $ns->getHostName();
            $i++;
        }

        return $data;
    }

    public function setNameservers($domain, $nameservers)
    {
        $info_res = $this->getDomain($domain);

        $nameservers = array_filter(
            $nameservers,
            function ($ns) {
                return !empty($ns);
            }
        );

        // Get the nameservers from domain info.
        $existing_ns = array_map(
            function ($ns) {
                return $ns->getHostName();
            },
            $info_res->getDomainNameservers()
        );

        foreach ($nameservers as $ns) {
            if (empty($ns)) {
                continue;
            }

            // Nameservers exist - we do not need to create them.
            if (!$this->checkNameservers($ns)) {
                continue;
            }

            // Create nameserver.
            $create_result = $this->createNameserver($ns);

            if (!$create_result) {
                return ['error' => 'Failed to create nameserver'];
            }
        }

        $delete_ns = array_diff($existing_ns, $nameservers);
        $add_ns    = array_diff($nameservers, $existing_ns);

        $del = null;
        $add = null;

        if (!empty($delete_ns)) {
            $del = new eppDomain($domain);
            foreach ($delete_ns as $ns_to_delete) {
                $ip_address = array_key_exists($ns_to_delete, self::$nameservers) ? array_flip(self::$nameservers[$ns_to_delete]) : null;
                $del->addHost(new eppHost($ns_to_delete, $ip_address));
            }
        }

        if (!empty($add_ns)) {
            $add = new eppDomain($domain);
            foreach ($add_ns as $ns_to_add) {
                $ip_address = array_key_exists($ns_to_add, self::$nameservers) ? array_flip(self::$nameservers[$ns_to_add]) : null;
                $add->addHost(new eppHost($ns_to_add, $ip_address));
            }
        }

        $d_obj = new eppDomain($domain);
        $change_request = new eppUpdateDomainRequest($d_obj, $add, $del);

        /**
         * Response override
         *
         * @var eppUpdateDomainResponse $response
         */
        $response = $this->client->writeandread($change_request);

        logModuleCall('RsReg', 'Nameserver change', $change_request->saveXML(), $response->saveXML());

        if (!$response->Success()) {
            throw new Exception("Failed to update nameservers");
        }
    }

    /**
     * Checks if the nameserver exists in the RsReg system
     *
     * @param  array|string $nameservers Nameservers array or string
     * @return bool                      True if nameservers exist
     */
    private function checkNameservers($nameservers)
    {
        $ns_to_check = [];

        if (!is_array($nameservers)) {
            $nameservers = [$nameservers];
        }

        foreach ($nameservers as $nameserver) {
            if (empty($nameserver)) {
                continue;
            }

            if (array_key_exists($nameserver, self::$nameservers)) {
                $ns_to_check[] = new eppHost($nameserver, array_flip(self::$nameservers[$nameserver]));
            } else {
                $ns_to_check[] = new eppHost($nameserver);
            }
        }

        $request  = new eppCheckRequest($ns_to_check);

        /**
         * Response override
         *
         * @var eppCheckResponse $response
         */
        $response = $this->client->writeandread($request);

        logModuleCall('RsReg', 'Check Nameserver', $request->saveXML(), $response->saveXML());

        return array_reduce(
            $response->getCheckedHosts(),
            function ($a, $v) {
                return $v && $a;
            },
            true
        );
    }

    /**
     * Creates a nameserver in rsreg system
     *
     * @param  string $nameserver Nameserver name.
     * @return bool               True if nameserver was created, false otherwise
     */
    private function createNameserver($nameserver)
    {
        $ip_address = array_key_exists($nameserver, self::$nameservers) ? array_flip(self::$nameservers[$nameserver]) : null;

        $host    = new eppHost($nameserver, $ip_address);
        $request = new eppCreateHostRequest($host);

        /**
         * Response override
         *
         * @var eppCreateResponse $response
         */
        $response = $this->client->writeandread($request);

        $message = is_null($ip_address) ? 'Create Nameserver' : 'Register Nameserver';

        logModuleCall('RsReg', $message, $request->saveXML(), $response->saveXML());

        return $response->Success();
    }

    /**
     * Get domain lock status.
     *
     * @param  string $domain Domain name.
     * @return string         Lock status.
     */
    public function getLock($domain)
    {
        $this->eppLogin();

        $d_obj = new eppDomain($domain);
        $info  = new eppInfoDomainRequest($d_obj);

        /**
         * Response override
         *
         * @var eppInfoDomainResponse $info_res
         */
        $info_res = $this->client->writeandread($info);

        logModuleCall('RsReg', 'Lock Info', $info->saveXML(), $info_res->saveXML());

        if (!$info_res->Success()) {
            throw new Exception("Failed to get domain info");
        }

        return in_array('clientUpdateProhibited', $info_res->getDomainStatuses()) ? 'locked' : 'unlocked';
    }

    /**
     * Set domain lock status
     *
     * @param  string $domain      Domain name.
     * @param  bool   $lock_status Lock status.
     * @return array              Lock status.
     */
    public function setLock($domain, $lock_status)
    {
        $d_obj = new eppDomain($domain);
        $info  = $this->getDomain($domain);

        $add = $d_obj;
        $del = $d_obj;

        if ($lock_status) {
            $add->addStatus('clientUpdateProhibited');
        } else {
            $del->addStatus('clientUpdateProhibited');
        }

        $request  = new eppUpdateDomainRequest($d_obj, $add, $del);
        $response = $this->client->writeandread($request);

        logModuleCall('RsReg', 'Lock change', $request->saveXML(), $response->saveXML());

        if (!$response->Success()) {
            throw new Exception("Failed to update domain lock");
        }

        return ['status' => "Please check your email for confirmation."];
    }

    /**
     * Get contacts for domain
     *
     * @param  string $domain Domain name.
     * @return array          Contact data.
     */
    public function getContacts($domain, $for_change = false)
    {
        $info_res = $this->getDomain($domain, 'Get Contacts');
        $d_obj    = $info_res->getDomain();
        $contacts = [];
        $output   = [];

        $contacts['registrant'] = $d_obj->getRegistrant();

        foreach ($d_obj->getContacts() as $contact) {
            $contacts[$contact->getContactType()] = $contact->getContactHandle();
        }

        foreach ($contacts as $type => $contact_id) {
            $contact  = new eppContactHandle($contact_id);
            $request  = new eppInfoContactRequest($contact);

            /**
             * Response override
             *
             * @var rnidsEppInfoContactResponse $response
             */
            $response = $this->client->writeandread($request);
            $dataset  = $response->getContact();
            $postal   = $dataset->getPostalInfo(0);

            if (!$response->Success()) {
                return['error' => "Failed to get contact info for $type contact"];
            }

            $name_array = explode(' ', $postal->getName());
            $first_name = '';
            $last_name  = '';

            if (count($name_array) > 2) {
                $first_name = array_shift($name_array);
                $last_name = implode(' ', array_slice($name_array, 1));
            } else {
                $first_name = array_shift($name_array);
                $last_name = array_shift($name_array);
            }

            $details = [
                'First Name'     => $first_name,
                'Last Name'      => $last_name,
                'Company Name'   => $response->getContactIsLegalEntity() === "true"
                    ? $postal->getOrganisationName()
                    : '',
                'Company Number' => $response->getContactIdent(),
                'Tax Number'     => $response->getContactVatNo(),
                'Address 1'      => $postal->getStreet(0),
                'Address 2'      => $postal->getStreet(1),
                'Postcode'       => $postal->getZipcode(),
                'City'           => $postal->getCity(),
                'State'          => $postal->getProvince(),
                'Country'        => $postal->getCountrycode(),
                'Email Address'  => $response->getContactEmail(),
                'Phone Number'   => $response->getContactVoice(),
            ];

            if ($for_change) {
                $details['ID'] = $response->getContactId();
            }

            $output[$type] = $details;
        }

        return $output;
    }

    private function getTaxNumber($params, $type): string
    {
        $real_id = $params['original']['model']['id'];
        $user_id = $params['original']['model']['userid'] ?? $real_id;

        if ($real_id !== $user_id) {
            $number_data = preg_replace('/\s+/', '', $params['original']['model']['tax_id']);
            $number_data = explode(',', $number_data);

            return $number_data[1] ?? '';
        }

        if (in_array($type, ['registrant', 'admin'])) {
            return $params['original']['model']['tax_id'];
        }

        return '111950421';
    }

    private function getContactInfo($params, $type)
    {

        $name     = '';
        $company  = '';
        $address1 = '';
        $address2 = '';
        $city = '';
        $postcode = '';
        $state = '';
        $country = '';
        $email = '';
        $phone = '';

        $tax_id = $this->getTaxNumber($params, $type);

        switch ($type) {
            case 'registrant':
            case 'admin':
                $company  = !empty($params['companyname']) && !empty($tax_id)
                    ? $params['companyname']
                    : '';
                $name     = empty($company) ? $params['firstname'] . ' ' . $params['lastname'] : '';
                $address1 = $params['address1'];
                $address2 = $params['address2'];
                $city     = $params['city'];
                $postcode = $params['postcode'];
                $state    = $params['state'];
                $country  = $params['countrycode'];
                $email    = $params['email'];
                $phone    = $params['phonenumberformatted'];
                break;
            case 'tech':
                return $params['admin_id'];
                break;
        }

        $data = [
            'name'     => $name,
            'company'  => $company,
            'pib'      => $company != '' ? $tax_id : '',
            'address1' => $address1,
            'address2' => $address2,
            'city'     => $city,
            'postcode' => $postcode,
            'state'    => $state,
            'country'  => $country,
            'email'    => $email,
            'phone'    => $phone,
        ];

        return $this->createContact($data);
    }

    private function createContact($data)
    {

        $ident_desc   = 'Ovo je opis koji je stavio Oblak Solutions.';
        $postal_info  = new eppContactPostalInfo(
            $data['name'],
            $data['city'],
            $data['country'],
            $data['company'],
            $data['address1'],
            $data['state'],
            $data['postcode'],
        );
        $contact_info = new eppContact($postal_info, $data['email'], $data['phone']);
        $contact_req  = new rnidsEppCreateContactRequest($contact_info, $data['pib'], $ident_desc, '2032-' . date('m-d'), !empty($data['company']), 'personal_ID', $data['pib']);

        /**
         * Response override
         *
         * @var eppCreateResponse $response
         */
        $response = $this->client->writeandread($contact_req);

        logModuleCall('RsReg', 'Create Contact', $contact_req->saveXML(), $response->saveXML());

        if (!$response->Success()) {
            throw new Exception('Failed to create contact');
        }

        return $response->getContactId();
    }

    public function changeTechContact($domain, $tech_id)
    {
        if (!self::$logged_in) {
            $this->eppLogin();
        }

        $existing_contacts = $this->getContacts($domain, true);



        $contact  = new eppContactHandle($tech_id);
        $request  = new eppInfoContactRequest($contact);

        // /**
        //  * Response override
        //  *
        //  * @var rnidsEppInfoContactResponse $response
        //  */
        // $response     = $this->client->writeandread($request);
        // $tech_contact = $response->getContact();
        // $tech_postal  = $tech_contact->getPostalInfo(0);

        // $name_array = explode(' ', $tech_postal->getName());
        // $first_name = '';
        // $last_name  = '';

        // if (count($name_array) > 2) {
        //     $first_name = array_shift($name_array);
        //     $last_name = implode(' ', array_slice($name_array, 1));
        // } else {
        //     $first_name = array_shift($name_array);
        //     $last_name = array_shift($name_array);
        // }

        // $company_name = $response->getContactIsLegalEntity() === "true" ? $tech_postal->getOrganisationName() : '';


        // $details = [
        //     'First Name'     => $first_name,
        //     'Last Name'      => $last_name,
        //     'Company Name'   => $response->getContactIsLegalEntity() === "true"
        //         ? $tech_postal->getOrganisationName()
        //         : '',
        //     'Company Number' => $response->getContactIdent(),
        //     'Tax Number'     => $response->getContactVatNo(),
        //     'Address 1'      => $tech_postal->getStreet(0),
        //     'Address 2'      => $tech_postal->getStreet(1),
        //     'Postcode'       => $tech_postal->getZipcode(),
        //     'City'           => $tech_postal->getCity(),
        //     'State'          => $tech_postal->getProvince(),
        //     'Country'        => $tech_postal->getCountrycode(),
        //     'Email Address'  => $response->getContactEmail(),
        //     'Phone Number'   => $response->getContactVoice(),
        // ];

        // $update = new eppContact($tech_postal, $response->getContactEmail(), $response->getContactVoice());
        // $update->contact_id = $tech_id;

        // $update_request = new rnidsEppUpdateContactRequest(eppCon);

        // // logModuleCall('RsReg', 'Change Tech Contact', $contact_update->saveXML(), $response->saveXML());

        // if (!$response->Success()) {
        //     throw new Exception('Failed to change tech contact');
        // }

        // return true;
    }

    private function searchCustomFields($fields, $field_id)
    {
        foreach ($fields as $field_data) {
            if ($field_data['id'] == $field_id) {
                return $field_data['value'];
            }
        }

        return '';
    }

    private function uuidv4()
    {
        $data = random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
