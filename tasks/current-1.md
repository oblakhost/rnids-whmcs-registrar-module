# Task 1: Refactor plan for Registrar class extraction

## Objective
Create a concrete, implementation-ready refactor plan for reducing `src/Registrar.php` bloat by extracting cohesive responsibilities into focused classes/services while keeping `Registrar` as the single entrypoint used by `rnids.php`. The plan should preserve WHMCS registrar contracts, `.rs` business rules, and current behavior.

## Implementation Plan
- [ ] Baseline and safety
  - Confirm current `Registrar` public API and WHMCS-facing return contracts stay unchanged.
  - Capture key responsibilities currently mixed in `Registrar` (validation, contact orchestration, nameserver/host sync, status mapping, client bootstrap, error formatting).
- [ ] Extract pure validation and mapping helpers first (low risk)
  - Create `src/Validation/DomainInputValidator.php` for domain/TLD/period/auth-code normalization and validation.
  - Create `src/Validation/RegistrationProfileResolver.php` for registrant type and company/custom-field mapping logic.
  - Create `src/Contact/ContactDataMapper.php` for inbound WHMCS contact normalization and submitted-contact extraction.
- [ ] Extract domain-state interpretation
  - Create `src/Domain/DomainStatusService.php` for status normalization and WHMCS registration-state derivation.
- [ ] Extract contact registry operations
  - Create `src/Contact/ContactService.php` for contact lookup/create and domain contact update payload building.
- [ ] Extract nameserver/host responsibilities
  - Create `src/Nameserver/KnownHostRepository.php` for loading and normalizing known host/IP data.
  - Create `src/Nameserver/HostService.php` for host existence and IP sync.
  - Create `src/Nameserver/DomainNameserverUpdater.php` for raw EPP nameserver update XML operations.
- [ ] Extract client bootstrap and error formatting
  - Create `src/Support/ClientFactory.php` to build RNIDS client from module params.
  - Create `src/Support/ErrorMessageFormatter.php` for safe user-facing error strings.
- [ ] Slim `Registrar` into orchestration-only role
  - Keep only high-level flow methods; replace inline logic with calls to extracted collaborators.
  - Ensure no per-handler raw command construction remains in `Registrar` when moved service exists.
- [ ] Verify contracts and deterministic behavior
  - Validate success/failure shapes for WHMCS compatibility.
  - Validate `.rs` TLD constraints, required nameserver count, transfer auth handling, and contact reassignment policy.

## Acceptance Criteria
- [ ] `src/Registrar.php` is significantly smaller and primarily orchestration.
- [ ] Extracted classes have single, clear responsibilities and reusable method boundaries.
- [ ] All existing public `Registrar` behaviors and return contracts remain compatible with WHMCS.
- [ ] Nameserver and contact flows keep current behavior (including safe failures) with no silent catches.
- [ ] Refactor aligns with `.clinerules/05-registrar-single-entrypoint.md`.

## Outcome
(To be filled on completion.)
