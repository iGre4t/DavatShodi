# Database portability

EGM, TaskClub, and generated mission runtime state is stored in MySQL. This includes registries, settings, events/periods, users, attendance, answers, teams, submissions, prizes, login attempts, activity history, invite cards, and uploaded assets.

For migration to another server:

1. Copy the application code at the same version.
2. Dump and restore the **entire configured database**, not only the `EGM` or `TC` registry tables. Per-instance tables use names such as `EGM_00000_*` and `TC_0001_*`.
3. Configure `api/config.php` for the restored database.
4. Open the main panel. Missing generated EGM/TaskClub code shells are reconstructed automatically from the registry and templates; no event data is written to the filesystem.
5. Run `php tests/database_portability_test.php` to verify document hashes, blob chunks, relationships, registries, and database-only mode.

Application PHP/JavaScript/CSS remains deployment code and is not stored in the database. All mutable EGM and TaskClub event state is database-owned.
