---
task: task_20260314_013042238_2ylqvy_implement_check_avialibility
---

## TODO

### Iteration 1
- [x] Skip automated test coverage per user instruction (`no test - flying blind`)
- [x] Implement RNIDS availability lookup mapping in the registrar service
- [x] Wire `rnids_CheckAvailability()` to the registrar service with logging and corrected phpdoc
- [x] Update technical notes for the availability behavior
- [x] Run syntax verification

### Iteration 2
- [x] Extract RNIDS availability lookup helpers into a dedicated domain service
- [x] Re-run syntax verification after the extraction

### Iteration 3
- [x] Align RNIDS availability input with classic `sld` + `tlds` contract while keeping fallback compatibility
- [x] Return `STATUS_TLD_NOT_SUPPORTED` results for unsupported TLDs
- [x] Re-run syntax verification after the contract rectification
