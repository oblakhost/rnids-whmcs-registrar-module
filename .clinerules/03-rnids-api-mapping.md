# RNIDS API Mapping Rules

This file defines how WHMCS registrar operations map to RNIDS / RsReg EPP client operations.

## Mapping Principles

- Centralize API call mapping in helper methods; do not duplicate command-building logic per handler.
- Keep WHMCS handler functions focused on: input validation → API call → response normalization.
- Every mapping must be deterministic between test and production modes.

## Core Operation Mapping

- `rnids_RegisterDomain` → EPP create flow for domain + contacts + nameservers.
- `rnids_TransferDomain` → EPP transfer request flow with authInfo handling.
- `rnids_RenewDomain` → EPP renew flow with explicit period.
- `rnids_GetDomainInformation` → EPP info flow and WHMCS key normalization.
- `rnids_GetNameservers` / `rnids_SaveNameservers` → EPP info/update host attributes.
- `rnids_GetRegistrarLock` / `rnids_SaveRegistrarLock` → EPP status read/update.
- `rnids_GetContactDetails` / `rnids_SaveContactDetails` → EPP contact info/update flows.

## Contact and Custom Field Mapping

- Respect WHMCS contact categories: `Registrant`, `Admin`, `Tech`, `Billing`.
- Registry-required local fields must map from configured custom field IDs:
  - `reg_mb` (company/identity number, when required)
  - `reg_pib` (tax number, when required)
  - `admin_id` (registry admin identifier when applicable)
- Missing required mapped values must fail fast with actionable errors.

## Domain and TLD Constraints

- Treat `.rs` business rules as first-class validations before API calls.
- Validate period ranges against registry support.
- Enforce nameserver and hostname format constraints before submit.

## Response Normalization

- Convert raw RNIDS responses into WHMCS-compatible shapes only.
- Keep a stable normalization layer for dates, status values, and lock states.
- Preserve useful diagnostics in logs, not in customer-visible error payloads.
