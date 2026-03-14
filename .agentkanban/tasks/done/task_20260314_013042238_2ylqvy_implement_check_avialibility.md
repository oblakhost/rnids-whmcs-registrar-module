---
title: Implement Check Avialibility
created: 2026-03-14T00:30:42.238Z
updated: 2026-03-14T02:50:36.247Z
description: |-
  implement rnids_CheckAvailability. php
  phpdoc there.

  We need to return 
  $results = new ResultsList();
   * @return \WHMCS\Domains\DomainLookup\ResultsList An ArrayObject based collection of \WHMCS\Domains\DomainLookup\SearchResult results

              $searchResult = new SearchResult($domain['sld'], $domain['tld']);
priority: high
sortOrder: 1
slug: implement_check_avialibility
---

## Conversation

[user]

[user]

can you please move those check methods into separate service in Domains folder

[agent]

Extracted the availability lookup helpers out of `Registrar` into a dedicated domain service.

What changed:
- Added `src/Domain/AvailabilityLookupService.php` for WHMCS availability input normalization, supported-TLD filtering, registry check mapping, and `ResultsList` construction.
- Kept `Registrar::checkAvailabilityFromWhmcs()` as a thin delegator so `rnids_CheckAvailability()` did not need to change behavior.
- Updated `TECHNICAL.md` to note the extraction and keep the design intent documented.

Verification:
- Ran `php -l src/Domain/AvailabilityLookupService.php`
- Result: `No syntax errors detected in src/Domain/AvailabilityLookupService.php`
- Ran `php -l src/Registrar.php`
- Result: `No syntax errors detected in src/Registrar.php`
- Ran `php -l rnids.php`
- Result: `No syntax errors detected in rnids.php`

[user]

implement rnids_CheckAvailability. php
phpdoc there.

We need to return 
$results = new ResultsList();
 * @return \WHMCS\Domains\DomainLookup\ResultsList An ArrayObject based collection of \WHMCS\Domains\DomainLookup\SearchResult results

            $searchResult = new SearchResult($domain['sld'], $domain['tld']);

[agent]

Implemented `rnids_CheckAvailability()` in `rnids.php` and moved the RNIDS-specific lookup logic into `Registrar::checkAvailabilityFromWhmcs()`.

What was done:
- Added proper WHMCS `ResultsList` return phpdoc and return type.
- Added supported-TLD filtering for RNIDS availability lookups and skipped unsupported TLDs entirely.
- Preferred `punyCodeSearchTerm` when present, otherwise used `searchTerm`.
- Mapped RNIDS `domain()->check()` results into WHMCS `SearchResult` statuses (`available for registration` vs `registered`).
- Added success/error module logging for availability checks.
- Updated `TECHNICAL.md` and created/completed the task TODO file.
- Per user instruction `no test - flying blind`, skipped automated test coverage and verified with syntax checks only.

Verification:
- Ran `php -l rnids.php`
- Result: `No syntax errors detected in rnids.php`
- Ran `php -l src/Registrar.php`
- Result: `No syntax errors detected in src/Registrar.php`

[user]
```php
function enom_CheckAvailability($params)
{
    $sld = $params["sld"];
    $tlds = $params["tlds"];
    foreach( $tlds as $key => $tld ) 
    {
        $tlds[$key] = ltrim($tld, ".");
    }
    $Enom = new CEnomInterface();
    $Enom->NewRequest();
    $Enom->AddParam("uid", $params["Username"]);
    $Enom->AddParam("pw", $params["Password"]);
    $Enom->AddParam("sld", $sld);
    $Enom->AddParam("TLDList", implode(",", $tlds));
    $Enom->AddParam("command", "check");
    $Enom->AddParam("IncludePrice", true);
    $Enom->DoTransaction($params);
    if( 0 < $Enom->Values["ErrCount"] ) 
    {
        throw new Exception($Enom->Values["Err1"]);
    }

    $premiumPricing = $premiumDomains = array(  );
    if( $params["premiumEnabled"] ) 
    {
        for( $i = 1; $i <= $Enom->Values["DomainCount"]; $i++ ) 
        {
            if( $Enom->Values["IsPremiumName" . $i] == "true" ) 
            {
                $premiumDomains[] = $Enom->Values["Domain" . $i];
            }

        }
    }

    if( $premiumDomains ) 
    {
        $type = (App::isInRequest("epp") ? "Transfer" : "Register");
        $eNom2 = new CEnomInterface();
        $eNom2->NewRequest();
        $eNom2->AddParam("uid", $params["Username"]);
        $eNom2->AddParam("pw", $params["Password"]);
        $i = 1;
        foreach( $premiumDomains as $domain ) 
        {
            $domainParts = explode(".", $domain, 2);
            $eNom2->AddParam("sld" . $i, $sld);
            $eNom2->AddParam("tld" . $i, $domainParts[1]);
            $i++;
        }
        $eNom2->AddParam("ProductType", "Renew");
        $eNom2->AddParam("command", "PE_GetPremiumPricing");
        $eNom2->DoTransaction($params);
        for( $x = 1; $x <= $i; $x++ ) 
        {
            if( $eNom2->Values["IsPremium" . $x] == "true" && $eNom2->Values["Price" . $x] != 0 ) 
            {
                $premiumPricing[$eNom2->Values["Domain" . $x]]["renew"] = $eNom2->Values["Price" . $x];
            }

        }
        $eNom2 = new CEnomInterface();
        $eNom2->NewRequest();
        $eNom2->AddParam("uid", $params["Username"]);
        $eNom2->AddParam("pw", $params["Password"]);
        $i = 1;
        foreach( $premiumDomains as $domain ) 
        {
            $domainParts = explode(".", $domain, 2);
            $eNom2->AddParam("sld" . $i, $sld);
            $eNom2->AddParam("tld" . $i, $domainParts[1]);
            if( $type == "Transfer" ) 
            {
                $eNom2->AddParam("AuthInfo" . $i, App::getFromRequest("epp"));
            }

            $i++;
        }
        $eNom2->AddParam("ProductType", $type);
        $eNom2->AddParam("command", "PE_GetPremiumPricing");
        $eNom2->DoTransaction($params);
        for( $x = 1; $x <= $i; $x++ ) 
        {
            if( $eNom2->Values["IsPremium" . $x] == "true" && $eNom2->Values["Price" . $x] != 0 ) 
            {
                $premiumPricing[$eNom2->Values["Domain" . $x]][strtolower($type)] = $eNom2->Values["Price" . $x];
            }

        }
    }

    $results = new WHMCS\Domains\DomainLookup\ResultsList();
    $domainCount = $Enom->Values["DomainCount"];
    for( $i = 1; $i <= $domainCount; $i++ ) 
    {
        $domain = $Enom->Values["Domain" . $i];
        $rrpCode = $Enom->Values["RRPCode" . $i];
        $parts = explode(".", $domain, 2);
        $domainSearchResult = new WHMCS\Domains\DomainLookup\SearchResult($parts[0], $parts[1]);
        if( $rrpCode == 210 ) 
        {
            $status = $domainSearchResult::STATUS_NOT_REGISTERED;
        }
        else
        {
            if( $rrpCode == 827 ) 
            {
                $status = $domainSearchResult::STATUS_TLD_NOT_SUPPORTED;
            }
            else
            {
                $status = $domainSearchResult::STATUS_REGISTERED;
            }

        }

        $domainSearchResult->setStatus($status);
        if( $params["premiumEnabled"] && $Enom->Values["IsPremiumName" . $i] == "true" && array_key_exists($Enom->Values["Domain" . $i], $premiumPricing) ) 
        {
            $domainSearchResult->setPremiumDomain(true);
            $pricing = $premiumPricing[$Enom->Values["Domain" . $i]];
            $pricing["CurrencyCode"] = "USD";
            $domainSearchResult->setPremiumCostPricing($pricing);
        }
        else
        {
            if( !$params["premiumEnabled"] && $Enom->Values["IsPremiumName" . $i] == "true" ) 
            {
                $domainSearchResult->setStatus($domainSearchResult::STATUS_RESERVED);
            }

        }

        $results[] = $domainSearchResult;
    }
    return $results;
}
```

This is how enom does it. 

[agent]

Rectified the RNIDS availability implementation against the `enom` example.

What changed:
- `rnids_CheckAvailability()` now documents and logs the classic `sld` + `tlds` contract, while still accepting `searchTerm` / `tldsToInclude` and `punyCodeSearchTerm` as compatibility fallbacks.
- `AvailabilityLookupService` now normalizes the input contract explicitly, partitions supported vs unsupported TLDs, preserves requested order, and returns `STATUS_TLD_NOT_SUPPORTED` results instead of silently skipping unsupported TLDs.
- `Registrar` stayed thin; the lookup behavior remains isolated in the dedicated domain service.
- `TECHNICAL.md` was updated to reflect the corrected availability contract and unsupported-TLD behavior.

Verification:
- Ran `php -l src/Domain/AvailabilityLookupService.php`
- Result: `No syntax errors detected in src/Domain/AvailabilityLookupService.php`
- Ran `php -l rnids.php`
- Result: `No syntax errors detected in rnids.php`
- Ran `php -l src/Registrar.php`
- Result: `No syntax errors detected in src/Registrar.php`

[user]
