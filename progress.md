Original prompt: Fix all identified Task Club prize-selection and card-flipping risks safely, without overwriting or damaging critical files.

- Implemented locked, atomic, versioned prize inventory and prize-level stores with strict validation and backup recovery.
- Implemented idempotent server-authoritative reward flips, structured CSV reward history, crash reconciliation, and a durable JSON award ledger.
- Retired legacy prize mutation endpoints and preserved prize history during invitee progress resets.
- PHP/JavaScript syntax checks and all focused repository tests pass.
- Local PHP smoke check returned HTTP 200 and rendered 351,462 bytes.
- The bundled Playwright dependency and in-app browser runtime were unavailable, so no screenshot was captured; transaction/UI harnesses and syntax checks passed instead.
