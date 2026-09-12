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

Previous user acceptance covers registration, nameserver and contact changes,
including the contact-approval banner and its clearing after approval.
Cross-registrar transfer and the transfer-completion hook still need live
acceptance coverage. Verification-email resend remains an admin-assisted workflow.
