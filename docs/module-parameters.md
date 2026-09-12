# WHMCS Registrar Module Parameters

Reference: https://developers.whmcs.com/domain-registrars/module-parameters/

This document summarizes the parameters WHMCS can pass to registrar module functions and highlights fields used by this RNIDS module.

## Common Parameters (Frequently Present)

- `registrar` — active registrar module name.
- `sld` — domain SLD.
- `tld` — domain TLD (without leading dot in many contexts).
- `domain` — full domain (when provided by WHMCS flow).
- `regperiod` — registration/renewal period in years.
- `userid` — WHMCS client ID.
- `firstname`, `lastname`, `companyname`
- `email`, `address1`, `address2`, `city`, `state`, `postcode`, `country`, `phonenumber`
- `dnsmanagement`, `emailforwarding`, `idprotection`
- module config values from `rnids_getConfigArray` (for this module: `epp_username`, `epp_password`, `epp_certificate`, `epp_certificate_password`, `epp_ca`, `reg_mb`, `reg_pib`, `admin_id`, `testmode`).

### TLS/Test mode behavior

- Production mode (`testmode` disabled): peer verification is enabled (`verifyPeer=true`, `verifyPeerName=true`).
- Test mode (`testmode` enabled): test endpoint `epp-test.rnids.rs` is used with relaxed TLS checks for sandbox compatibility.
- The test endpoint requires an explicit EPP hello and may omit the client transaction ID in replies. Test mode enables these SDK compatibility options. A present, mismatched transaction ID still fails. Production retains unsolicited greeting and required transaction IDs.
- Each registrar instance owns its own lazy client; credentials and endpoint settings are never shared through a static connection.
- `epp_certificate_password` is optional and used only when the client certificate is passphrase-protected.

## Contact-Related Parameters

For contact functions (`GetContactDetails`, `SaveContactDetails`), WHMCS can provide contact data in different shapes depending on UI flow and whether **Use contact details from WHMCS profile** is selected.

### Role-based structure

`$params['contactdetails']` and/or role arrays can contain:

- `Registrant`
- `Admin`
- `Tech`
- `Billing`

Each role can contain keys such as:

- `First Name`, `Last Name`, `Company Name`
- `Address 1`, `Address 2`, `City`, `State`, `Postcode`, `Country`
- `Email Address`, `Phone Number`
- custom module-specific values (in this module: `Company Number`, `Tax Number`)

### Flat fallback structure

In some flows WHMCS may provide only flat client fields (`firstname`, `lastname`, `address1`, etc.) without complete role blocks. Module logic should therefore normalize both role-based and flat input.
Flat fields are used only when no explicit contact role arrays were submitted.
Submitting an Admin role alone changes only Admin. Changed details create a new
contact and reassign that role; existing contact objects are never edited.
Both street address lines participate in display and change detection.
RNIDS registrant changes are separate approval requests. A save acknowledges
submission; the old registrant may remain visible until approval. Mixed role
edits apply Admin/Tech changes first and submit the standalone registrant change
last. `ContactCreated` diagnostics retain only the new handle and role so pending
or failed assignments can be reconciled without logging personal contact fields.

Child-host registration checks existing glue before calling create, because the
test registry can treat create as an update. An exact single-IP match succeeds
without mutation; a conflict directs the user to Modify Nameserver. Equivalent
IPv6 spellings compare as the same address.

## RNIDS Mapping Notes

This module normalizes common WHMCS key variants before sending data to RNIDS EPP, including:

- `First Name` / `firstname` / `first_name`
- `Last Name` / `lastname` / `last_name`
- `Address 1` / `address1`
- `Address 2` / `address2`
- `Email Address` / `email`
- `Phone Number` / `phonenumber` / `phone`
- `Company Name` / `companyname`

For legal entities in this module:

- if `Company Name`, `Company Number`, and `Tax Number` are present,
- first/last name are treated as optional and company identity is used for contact create payload.

For registration, an empty company number is filled from the registrant's resolved
tax number / VAT ID (`tax_id`, `VAT ID`, `vat_id`, and the existing tax/VAT aliases).
Explicit company numbers, including mapped `reg_mb` values, take precedence.
Identifiers are passed through after trimming surrounding whitespace; the module
does not check their length, format, checksum, or accuracy. The registrant is
responsible for providing correct information. An explicitly selected registrant
does not inherit the account holder's VAT ID.

Registration checks the required registrant payload fields and
configured technical handle before opening an EPP connection.

The optional `rnids.additionalfields.php` definitions default company-only TLDs
to Company and allow blank Company Number and Tax Number overrides so registration
can use the registrant VAT ID. Include that file from WHMCS
`resources/domains/additionalfields.php` if domain-specific checkout fields are
wanted. Configure `.co.rs` Auto Registration as `rnids` in WHMCS Domain Pricing;
registrar routing is a WHMCS setting, independent of the field mapping.

Domain inputs accept Unicode or equivalent Punycode for supported RNIDS suffixes.
The module uses canonical Unicode for lookup results and the SDK emits ASCII IDNA
names in EPP XML. Unicode input requires PHP intl. Periods must be whole years
from 1 through 10; fractional or loosely numeric values are rejected.

Unlocking a registry-imposed lock returns an actionable error. A pending transfer
returns `completed=false` from TransferSync until that status clears.
