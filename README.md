# Coffee Multivendor

Multi-vendor kiosk ordering application with separate customer and vendor interfaces.

Vendors can operate as kiosks/food trucks or as table-service restaurants. Restaurant mode adds table QR codes, persistent open bills, guest-labelled ordering rounds, kitchen notes, service charges, split payment recording, and table cash-up.

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

## Vendor 1 sample coffee shop

Load the complete South African coffee-shop demonstration menu into Vendor 1:

```bash
php scripts/seed_sample_coffee_shop.php --vendor=1
```

The seed is idempotent: matching products are updated and unrelated existing products are preserved. To reset Vendor 1 to exactly the sample menu, use `--replace`. The replacement option deletes that vendor's existing menu products before inserting the sample and should only be used on demonstration data.

Where the configured database user has database-creation permission, run `php tests/migration_smoke.php --confirm-disposable` to copy only the current schema into a uniquely named disposable database, apply the migration, verify required objects, and remove the test database.
