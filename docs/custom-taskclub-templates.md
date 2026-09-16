# Custom TaskClub code templates

The creator offers Task Club and Custom TaskClub. Custom TaskClub selects a
developer-defined code template; it does not turn features on or off at runtime.
The catalog contains Standard Task Club (the existing implementation) and Iran Map
(the existing experience plus an Iran map side slide).

To add an experience, implement a complete Task Club source tree and register a
stable ID, display name, description and project-relative directory in
`api/lib/tc-templates.php`. The catalog is PHP deployment code, not editable
club settings. Never reuse an ID for an unrelated experience.

Template contract:

- Supply `TCM.php`, `TC Panel.php` and their dependencies, including the existing
  database runtime and permission/CSRF protections.
- Author paths using the existing source conventions: `mini apps/Task Club`,
  `mini%20apps/Task%20Club`, and project imports at `../../`. The generator and
  restore process rewrite these for `mini apps/missions/<name>`.
- Implement the template's features in its PHP/JS/CSS code. Supply a complete
  tree, not an overlay; the standard template is not copied first.
- Keep participant/event data out of the code tree. New clubs initialize empty
  tasks, periods and participants with their own database storage. JSON runtime
  files are excluded from code copying; templates should not depend on bundled
  JSON assets or seeded runtime data.
- Branch updates copy from the original selected template and retain event
  data. As with the existing updater, they do not remove obsolete code files.

The `code_template` instance data record stores `type` and `templateId` separately
from mutable metadata. Existing clubs without that record use Standard Task
Club. Missing or unknown saved templates fail explicitly during update/restore;
they never silently become standard clubs. Deploy the catalog and all registered
source trees along with the application when importing a database elsewhere.
