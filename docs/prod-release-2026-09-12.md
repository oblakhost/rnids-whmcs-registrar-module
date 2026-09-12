# Production release verification — 12 September 2026

The v1.0.1 artifact passed the module's 316 offline checks and contained SDK
v2.0.1. Production preflight on `oblak.root` found that `epp.rnids.rs:700`
requires the same explicit EPP hello and missing-client-transaction-ID
compatibility as the test endpoint. The v1.0.1 module was not activated.

Waiting for an unsolicited greeting timed out after the TLS connection opened.
Sending hello with required transaction IDs reached login but failed transaction
validation. Allowing an absent transaction ID produced login result 1000 and
confirmed that the login response omitted that ID. A read-only domain info
request also returned 1000, followed by successful logout. Certificate peer and
hostname verification remained enabled, and self-signed certificates remained
disallowed throughout. No registry objects were modified.

The v1.0.2 patch applies those observed protocol settings to production. The SDK
continues to reject a present, mismatched transaction ID. Updated contract checks
failed for both old production settings before the fix.

The user authorized migrating existing legacy `rsreg` domain records and RNIDS
TLD defaults, including `.срб`, to `rnids`. Deployment uses the published ZIP and
retains a private legacy-module backup and the encrypted registrar configuration
and routing snapshot for rollback.

## Completed deployment

- Published [v1.0.2](https://github.com/oblakhost/rnids-whmcs-registrar-module/releases/tag/v1.0.2)
  from master commit `29a8e1cc88132b40009963c2572cc5783cbaa612`;
  [release workflow 34710740231](https://github.com/oblakhost/rnids-whmcs-registrar-module/actions/runs/34710740231)
  succeeded. ZIP SHA-256:
  `99a04d8f14f03a24d0db3518ff61ace74302abbcbb700353394a0d2d8803df2a`.
- Installed under `/home/oblakwhmcs/public_html/modules/registrars/rnids` on
  `oblak.root`. All 219 packaged files match the published ZIP. Site-specific
  `knownhosts.php` preserves the registry's current addresses for ns1–ns4;
  ns5–ns8 were absent from RNIDS and were not created.
- In one InnoDB transaction, migrated 1,851 domain records (882 active) and the
  six existing `.rs`, `.co.rs`, `.org.rs`, `.edu.rs`, `.in.rs`, `.срб` TLD defaults
  to `rnids`. Domain statuses were verified unchanged. There are no remaining
  `rsreg` domain records.
- Rerouted pending transfer job 2389 for domain record 3628 to `rnids`; the job
  remains pending and was not executed. Rebuilt the registrar hook cache and
  confirmed a fresh WHMCS process automatically loads `Rnids_Hooks`.
- The published module passed WHMCS configuration validation, production EPP
  login, configured technical-contact lookup, and logout with strict TLS.
  Fresh WHMCS registrar calls passed domain information, nameserver and contact
  reads for `.rs` record 361 and `.co.rs` record 1024. The client-area URL returned
  HTTP 200 after redirecting to login. No production registration, renewal,
  transfer or contact mutation was performed for verification.
- Backups and private migration/rollback scripts remain in
  `/home/oblakwhmcs/.rnids-release-20260912-52twubjg`. The old module and settings
  remain available. Rollback aborts if newly routed domains, TLD defaults or
  pending jobs would be left without the new registrar configuration.

All 316 module checks passed after the compatibility patch. Changed PHP files
passed PHP 8.1 syntax checks; 21 module/deployment PHP files were also linted on
the live host using `php -n -l`. The live host's normally configured CLI loader
segfaulted in lint mode, while normal WHMCS bootstrap and EPP runtime checks
passed. No PHP runtime configuration was changed.

Previous user acceptance covers registration, nameserver and contact changes,
including the contact-approval banner and its clearing after approval.
Cross-registrar transfer and the transfer-completion hook still need live
acceptance coverage. Verification-email resend remains an admin-assisted workflow.
