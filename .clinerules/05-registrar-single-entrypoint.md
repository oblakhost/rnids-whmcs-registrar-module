# Registrar Single Entrypoint Architecture

To keep RNIDS registrar behavior deterministic and maintainable, all registrar business logic must flow through `Oblak\WHMCS\RSREG\Registrar`.

## Rules

- `rnids.php` functions are thin adapters only:
  - accept WHMCS params
  - call the corresponding `Registrar` method
  - map/log errors safely
- Input mapping and response normalization belong to dedicated model/mapper classes (for example `InfoNormalizer`, `ContactNormalizer`).
- Avoid per-handler inline EPP/RNIDS command construction in `rnids.php`.
- Reuse shared helper methods in `Registrar` for:
  - domain/contact resolution
  - validation
  - request payload assembly
  - exception-safe error messages

## Contact Change Policy

- `SaveContactDetails` must **not** update existing contact objects.
- For changed contact roles, create new contact objects and reassign domain handles.
- Registrant changes are done via domain registrant reassignment.
- Admin/Tech changes are done via domain update add/remove contact handle operations.
