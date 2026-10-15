# Panel permissions

## Administration

Open **کاربران → دسترسی‌ها**. Search by event name or section.

- New accounts receive only **خانه** and **تنظیمات حساب**.
- Select an individual EGM or TaskClub, then its sections, for limited access.
- **مدیریت مهمانان (همه رویدادها)** and **باشگاه تعاملی (همه باشگاه‌ها)** are global grants. Their selected sections apply to current and future instances. Individual selections are additive; they do not override a global grant.
- Global module access also opens its creator/manager. Individual event access does not.
- Hiding an event from the sidebar does not remove it from the permission editor or grant access to it.
- Existing complete legacy EGM grants without a customization marker retain all current EGM sections. Explicit custom selections remain restricted.
- An explicit empty or invalid permission list means no access. Legacy NULL/empty-string permissions retain their historical administrative meaning.

## Dynamic catalog

`api/lib/tab-permissions.php` is the shared catalog for frontend editing, validation and server checks. EGM/TaskClub registry records automatically become permission groups on the next request. Each instance inherits its module's section definitions, so adding a section to the catalog exposes it for every instance without maintaining separate event lists.

Permissions use the existing `users.permissions` JSON column. No new permission table or database migration is required.

The requested PHP script resolves event scope, including materialized event directories. API guards reload the user's current database permissions on each authenticated request, so revoked permissions cannot survive through a stale session.

The shared EGM materializer refreshes affected panel and API files in existing instances after deployment.

## Verification

### Facilitator Windows access

In an EGM, open **تسهیلگرها → ویرایش** and select **دسترسی برنامه ویندوز**:

- **فقط اسکن**: scan results only.
- **اسکن و اطلاعات عمومی رویداد**: scanning and event information.
- **اسکن و اطلاعات مهمانان**: adds guest details and scan actions.
- **دسترسی کامل**: also settings and printing; configured PIN locks still apply.

Facilitators sign into the existing Windows app with their facilitator username/password. Only EGMs with matching credentials are accessible. New and legacy accounts without a saved Windows level default to scan only. Role changes apply on the next API request; removing an account or changing its credentials revokes its existing membership. Desktop authentication uses a separate session from panel.php. No database schema migration or new Windows binary is required for this server capability.

Verification: `php tests/egm_facilitators_test.php`, `node tests/egm_facilitators_ui_test.cjs`, and `node tests/egm_winapp_facilitators_test.cjs` (isolated MySQL database, real HTTP endpoint).

### Event staff roles

The **تسهیلگرها** pane also assigns **مدیر سیستم** (`system_manager`), **سرداور** (`head_referee`) or **داور** (`referee`). Every facilitator and eligible panel user defaults to referee. The pane lists panel users whose current permission grants include this EGM, including global EGM grants; removing their panel access removes them from this list and prevents new role assignments.

Facilitator roles are stored in their existing event account record. Panel user roles are stored separately in the event's `panel_staff_roles` database data. Assignments are isolated per EGM, survive password-only edits/imports, and are read fresh for authenticated event actors. This role is separate from the four Windows access levels and does not grant panel access or override Windows/PIN permissions. As requested, system manager and head referee currently have no additional action privileges.

- `php tests/panel_permissions_test.php`: isolated database; registry discovery, hidden/new events, instance isolation, server/API denial, new-account defaults, legacy compatibility and live revocation.
- `node tests/panel_permissions_frontend_test.cjs`: actual frontend normalization; scoped selections, navigation aliases and denial/default behavior.
- `php tests/database_instance_materializer_test.php`: refreshed instance code preserves database-owned event data.
