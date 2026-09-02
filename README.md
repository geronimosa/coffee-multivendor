# Coffee Multivendor

Multi-vendor kiosk ordering application with separate customer and vendor interfaces.

This is a sanitized baseline of the original demonstration application. Runtime credentials, uploaded till slips, temporary files, and customer data are excluded.

Copy `.env.example` to `.env` and provide environment-specific values. Never commit `.env` or production credentials.

The planned modernization adds vendor isolation, secure authentication, per-vendor Yoco and WhatsApp integrations, verified payment webhooks, and accounting/reconciliation records.

## Multi-vendor foundation

The new Super Admin interface lives at `/super/`. Before using it:

1. Copy `.env.example` to `.env` and configure the database.
2. Run `php scripts/generate_app_key.php` once and store its output as `APP_KEY`. Back this key up securely; losing it makes vendor credentials unreadable.
3. Back up the database, then run `php scripts/migrate.php`.
4. Create the first administrator with `php scripts/create_super_admin.php you@example.com "Your Name"`.

Do not run migrations directly against production until they have been tested on a restored copy of the demo database.

Where the configured database user has database-creation permission, run `php tests/migration_smoke.php --confirm-disposable` to copy only the current schema into a uniquely named disposable database, apply the migration, verify required objects, and remove the test database.
