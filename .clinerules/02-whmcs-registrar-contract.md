# WHMCS Registrar Function Contract

This file defines **implementation contracts** for `rnids.php` registrar handlers.

## General Rules

- Keep function signatures compatible with WHMCS registrar module expectations.
- On success, return only data expected by WHMCS for that function.
- On failure, return `['error' => '...']` with safe and actionable text.
- Never return raw stack traces, credentials, certificate data, or EPP frames with secrets.

## Function Return Expectations

### `rnids_getConfigArray`
- Must expose required credentials and registry-specific mapping fields.
- Field descriptions must be explicit enough for WHMCS admins.

### `rnids_config_validate`
- Must perform a real connectivity/login check.
- Must throw `InvalidConfiguration` when credentials or TLS setup are invalid.

### `rnids_RegisterDomain`
- Validate domain, period, and required contact/custom fields before API call.
- Return success in WHMCS-compatible shape; return `error` on failure.

### `rnids_TransferDomain`
- Validate transfer token/auth code and required registrant/admin context.
- Return transfer initiation result, or `error`.

### `rnids_RenewDomain`
- Validate renewal period and eligibility.
- Return success or `error` with clear reason.

### `rnids_GetDomainInformation`
- Return WHMCS-compatible keys for status-related domain information.
- Include expiry and lock state if available from registry response.

### `rnids_GetNameservers` / `rnids_SaveNameservers`
- Read and write nameserver hostnames in WHMCS-compatible format.
- Validate hostname format and registry constraints.

### `rnids_GetRegistrarLock` / `rnids_SaveRegistrarLock`
- Handle lock state as boolean-like WHMCS values.
- Avoid ambiguous states; fail clearly when unavailable.

### `rnids_GetContactDetails` / `rnids_SaveContactDetails`
- Return/update structured contact sets required by WHMCS.
- Preserve registry-required fields and local custom-field mapping.

## Consistency Rules

- Do not leave production-scope handlers empty.
- Use shared helpers for validation, response normalization, and error mapping.
- Keep behavior deterministic across test and production modes.
