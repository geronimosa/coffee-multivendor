# QRKiosk development handover

Last verified: 2 September 2026 (Africa/Johannesburg)

## Repository and environments

- GitHub: `https://github.com/geronimosa/coffee-multivendor`
- Working branch: `feature/branded-storefront`
- Latest functional commit before this handover: `32be3f5fb0046ab9b4ac4a77b0a7ad9464506a82`
- The commit containing this file is the current branch HEAD; verify with `git rev-parse HEAD` after cloning.
- Live URL: `https://coffee.tatu.co.za`
- SSH host: `steve@65.108.57.54`
- Server git worktree: `/home/steve/coffee-multivendor`
- Live document root: `/var/www/coffee`
- Deployment user/group for application files: `coffeeshop:coffeeshop`
- Local development mirror used by the previous Codex session: `working/coffee-multivendor` inside the ChatGPT project workspace. It intentionally has no `.git`; the server worktree is the authoritative git checkout.

## Current status

The live site is a PHP/MySQL multi-vendor kiosk demo being evolved into QRKiosk, an event-focused ordering platform. Customers order and pay at their leisure and collect only when ready. Each vendor has an independent customer storefront, staff fulfilment portal, brand configuration, credentials, products and orders.

Completed and deployed:

- Public event landing page at `/`, including original optimized multi-vendor event artwork.
- Pretty vendor customer URLs: `/shop/{vendor-slug}`.
- Pretty vendor staff URLs: `/vendor/{vendor-slug}`.
- Super-admin area at `/super/` with password/setup-token authentication.
- Super-admin account email corrected to `geronimosa@gmail.com`; never place its password in source or this file.
- Vendor create/edit UI with owner assignment, active/suspended status and contact details.
- Vendor-specific storefront themes: primary/accent/background/surface/text colors, logo, hero image and storefront message.
- Compact phone/tablet customer menu, cart and checkout layout.
- Cart routes corrected to use absolute URLs.
- Compact phone/tablet fulfilment queues: Pending, Preparing, Ready, Collected and Archived.
- Payment badges: red `NO PAYMENT`, amber `PAY AT COUNTER`, green `PAID`.
- Pay-at-counter orders can move Pending -> Preparing -> Ready before payment; they cannot move to Collected until staff confirms payment.
- Per-vendor encrypted integration records for Yoco, SnapScan, Zapper and Twilio/WhatsApp. The UI never redisplays saved secrets; it shows masked hints only.
- Vendor overview with customer shop, staff portal and edit actions.
- Audit log foundation, password setup tokens and migration runner.
- Migration smoke test using a disposable database.

## Applied database migrations

Verified in production:

1. `001_multivendor_foundation.sql`
2. `002_vendor_branding.sql`
3. `003_password_setup_tokens.sql`
4. `004_order_payment_status.sql`

Do not modify an applied migration. Add the next numbered migration and run `php scripts/migrate.php` from the server git worktree before deploying code that depends on it.

## Required environment-variable names

Values belong only in the protected live `.env`; never commit or print them.

- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `APP_KEY`
- `SESSION_NAME`
- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASSWORD`
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_USERNAME`
- `SMTP_PASSWORD`
- `SMTP_FROM_ADDRESS`
- `SMTP_FROM_NAME`
- `TWILIO_ACCOUNT_SID`
- `TWILIO_AUTH_TOKEN`
- `TWILIO_WHATSAPP_FROM`
- `TWILIO_CONTENT_SID_ORDER_READY`
- `TWILIO_TEST_TO`
- `EMAIL_PROBE_ENDPOINT`
- `EMAIL_PROBE_TOKEN`

Per-vendor Yoco, SnapScan, Zapper and WhatsApp credentials are encrypted in `vendor_integrations` with `APP_KEY`; they are not environment variables and must never be logged or committed.

## Uncommitted changes

The authoritative server worktree was clean before creating this handover. This file is the only intended new source change. If `git status --short` shows anything else after cloning, inspect it and do not discard it blindly.

Exclude from every commit and copy operation:

- `.env` and credential files
- payment/API secrets
- customer/order exports and database dumps
- logs and temporary files
- `images/` vendor/customer-generated uploads
- `vendor/` and `assets/vendor/` installed dependencies
- `tmp/`

## Known issues and unfinished work

- Yoco credential storage exists, but Yoco checkout creation, return handling, webhook signature verification and payment reconciliation are not implemented yet.
- Zapper credential storage exists, but invoice/app-link creation, notifications/webhooks and reconciliation are not implemented yet.
- SnapScan has legacy payment files. `pay_snapscan.php` still reads old `restaurants.snapscan_code` / `snapscan_api_key` columns instead of encrypted `vendor_integrations`, and contains obsolete redirect URLs. Migrate it before treating SnapScan as production-ready.
- `admin/snaphook.php` updates modern payment fields but requires a complete security review and must be wired to encrypted vendor credentials and verified webhook authentication.
- WhatsApp sending in `includes/whatsapp.php` still reads global environment credentials rather than the vendor's encrypted Twilio integration.
- `pay_by_card.php` is legacy-named code for “pay at counter”; it needs CSRF/ownership hardening and should become a proper checkout action.
- A complete backend accounting/reporting system for sales, payments, refunds, fees, settlements and reconciliation is not built.
- Vendor self-service menu management needs a focused review for vendor scoping, permissions and modern mobile UI.
- Automated application tests are minimal; only PHP lint and migration smoke coverage currently exist.
- This remains a live demo. Do not market gateways as certified/production-ready until their end-to-end integrations and security tests are complete.

## Exact next development task

Implement the first production-grade Yoco payment flow end to end:

1. Read the enabled vendor's encrypted Yoco configuration through `vendor_integrations`.
2. Create a server-side Yoco checkout for the exact order amount and immutable order/vendor reference.
3. Store a payment-attempt record rather than trusting browser redirects.
4. Add a vendor-resolving webhook endpoint with signature/authenticity verification and idempotent event handling.
5. Mark an order paid only from verified server-to-server payment confirmation.
6. Preserve pay-at-counter as a separate choice.
7. Add reconciliation fields/events suitable for the future accounting ledger.
8. Add tests for wrong vendor, altered amount, duplicate webhook, invalid signature, failed payment and successful payment.
9. Test with Yoco test credentials before enabling any live key.

Do not start Zapper or refactor SnapScan until the payment-attempt model and Yoco verification pattern are established, because the same model should support all gateways.

## Safe development and testing

From a fresh computer:

```bash
git clone https://github.com/geronimosa/coffee-multivendor.git
cd coffee-multivendor
git switch feature/branded-storefront
git status --short
git log -5 --oneline
```

Create a local `.env` from `.env.example` without committing it. Use a disposable/local database and never connect local development directly to production.

Before commit:

```bash
find . -name '*.php' -not -path './vendor/*' -not -path './assets/vendor/*' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short
```

On the server, the disposable migration smoke test is:

```bash
cd /home/steve/coffee-multivendor
php tests/migration_smoke.php --confirm-disposable
```

## Deployment procedure

1. Commit and push intended source changes to `feature/branded-storefront`.
2. Sync/pull them into `/home/steve/coffee-multivendor` and confirm `git status --short` is clean.
3. Create a new source and database backup before migrations or material changes.
4. Run `php tests/migration_smoke.php --confirm-disposable`.
5. If applicable, run `php scripts/migrate.php` from `/home/steve/coffee-multivendor`.
6. Deploy without overwriting protected runtime state:

```bash
sudo rsync -rlptD --chown=coffeeshop:coffeeshop \
  --exclude=.git --exclude=.env --exclude=/images/ --exclude=/vendor/ \
  --exclude=/tmp/ --exclude=/assets/vendor/ \
  /home/steve/coffee-multivendor/ /var/www/coffee/
```

7. Verify the public landing page, one `/shop/{slug}` storefront, its cart, `/vendor/{slug}`, `/super/`, and the affected webhook/API route.
8. Never test a real charge or send a real WhatsApp message without explicit authorization.

The GitHub repository-specific deploy key on the server is `/home/steve/.ssh/coffee_multivendor_github`. If the normal push cannot find it, use it explicitly without printing or copying the private key:

```bash
GIT_SSH_COMMAND="ssh -i /home/steve/.ssh/coffee_multivendor_github -o IdentitiesOnly=yes" \
  git push origin feature/branded-storefront
```

## Backup and rollback

Known pre-foundation backup:

- `/home/steve/coffee_backups/pre_deploy_20260902_163540/coffee_files.tar.gz`
- `/home/steve/coffee_backups/pre_deploy_20260902_163540/database.sql.gz`

Before each material deployment, create a new timestamped directory under `/home/steve/coffee_backups/` containing both a compressed live document-root archive and compressed database dump. Verify both files are non-empty before proceeding. Keep dumps outside the repository and web root.

For code-only rollback, resolve the known-good commit in the server worktree, deploy that tree with the same protected-path exclusions, and do not use `git reset --hard` on an unknown/dirty worktree. For a database rollback, stop application writes, restore the matching database dump using protected credentials, deploy the matching code archive/commit, then run smoke checks. Database restoration is destructive and requires explicit owner approval.

Do not automatically roll back a migration by editing or deleting rows in `schema_migrations`. Use a reviewed forward-fix migration unless a coordinated full database restore is explicitly approved.
