# Pending EGM updates

Baseline deployed: EGM-General-Draw-to-Current-2026-10-10-r2.

## 1. Gift eligibility requires successful checkout (deployed 2026-10-10)

- Existing automatic and manager gift approval rules remain provisional.
- Final «دریافت هدیه» is Yes only with recorded entry and checkout, correct presence, no forced presence, and gift approval, within the same period.
- Check-in alone, failed or forced checkout, and manager rejection do not qualify.
- Shared web/Windows scan data and guest, ticket and game Excel exports use the same final rule. Telegram/Bale exports use these shared exporters.
- Stored approval remains separate so manager decisions still apply before checkout and do not bypass checkout.
- No database migration required; existing attendance records are evaluated when read.

Files for the next host update:

- api/lib/egm-benefits.php
- api/lib/egm-check-in.php
- api/lib/egm-period-exports.php
- api/lib/egm-game-exports.php

Verification: tests/egm_gift_checkout_test.php and tests/egm_check_in_test.php.

Deployed and verified in EGM-Gift-WinApp-3.0.0-2026-10-10. Host backup is outside public_html under .egm-update-backups. Windows release 3.0.0 is published; live manifest works, but executable download currently returns HTTP 402 from WCDN, so live automatic download is not yet verified. Local replacement/restart smoke test passed.


Cleanup verified in cPanel: all 35 deployment ZIPs removed from public_html, including current package. No root ZIP files remain.

## Confirmed competition cards (deployed 2026-10-10)
Confirmed cards stay face up and disabled, survive reload from saved period state, and are excluded from preview animation and server roll/confirmation. Existing reset releases them. Shared general and period draw code. Verified database regression and Playwright animation/keyboard tests. Package: EGM-Confirmed-Cards-2026-10-10.zip.

Host installer verified 8 files: shared backend, developer template and all 6 EGM instance scripts. Live هم‌کوک script verified. Installer, staging directory and deployment ZIP removed after apply.

## Facilitator general draw login (deployed 2026-10-10)
General draw accepts existing EGM facilitator credentials as well as panel users. Separate draw session, event-bound facilitator access, account deletion/credential change revocation, logout clears both identities. Shared draw and nine-card endpoint authorization. Database session regression passed. Package: EGM-Draw-Facilitator-Login-2026-10-10.zip.

Host installer applied and hash-verified both shared authentication/authorization files. Backup kept outside public_html; deployment ZIP, staging files and installer removed. Real facilitator login still requires a user test with their own credentials.

## Deployed 2026-10-10: Guest reports controls and checkout filtering
Verified host hashes in outputs/egm-reports-host-result.json. Fixed pane click reload, preserved drafts, released response before bot edits. Bare not-found scans and blocked/ordinary checkout attempts excluded; forced checkout included. Historical excluded reports hidden while original logs retained. Updated shared files, template endpoint, and generated EGM endpoints. Installer removed itself; backups outside public_html.
