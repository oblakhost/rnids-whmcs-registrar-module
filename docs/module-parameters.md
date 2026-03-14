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

- if `Company Name` is present and both `Company Number` (8 digits) and `Tax Number` (9 digits) are valid,
- first/last name are treated as optional and company identity is used for contact create payload.
