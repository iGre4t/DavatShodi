## Local MySQL setup for Great Panel

This project now stores its data in a local MySQL table so you can switch to cPanel later without changing the frontend.

1. **Create a database/user**  
   * Open your XAMPP control panel and make sure MySQL is running.  
   * Use phpMyAdmin or the MySQL CLI to create a database (`great_panel` by default) and a user with privileges on that database.

2. **Apply the schema**  
   * Run `api/schema.sql` once (e.g., via phpMyAdmin's Import or `mysql < api/schema.sql`).  
   * The script creates the `great_panel_store` table that holds the JSON payload that the API reads and writes.

3. **Review `api/config.php`**  
   * The file ships with sane defaults for a local XAMPP install (`root` / no password).  
   * Change the values or set environment variables (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_TABLE`, `DB_RECORD`) to point to your database.  
   * The same file can live on cPanel—the only thing to update is the environment (or the config values) so it points to your hosted MySQL instance.

4. **Keep the JSON fallback**
   * `data/store.json` still exists so the app keeps a local copy of the payload; it is updated whenever the API serves or accepts changes.  
   * When you deploy to cPanel, the backend will read/write from the MySQL table by default, but you can keep the JSON file for debugging/local inspection.

5. **Migrating to cPanel**
   1. Export the `great_panel_store` table data (e.g., via phpMyAdmin).  
   2. Import the SQL on the hosted database and update `api/config.php` (or relevant environment variables) to use your hosted credentials.  
   3. Ensure the schema matches `api/schema.sql` on the target server so the API can write the JSON payload correctly.
