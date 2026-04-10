# Local Development Setup

## Prerequisites

- PHP 8.4 (via Laravel Herd)
- Node.js 20+
- Composer
- PostgreSQL 17 (via Homebrew)

## 1. Clone & Install

```bash
git clone <repo-url> cyclowax-dashboard
cd cyclowax-dashboard
composer install
npm install
cp .env.example .env
php artisan key:generate
```

## 2. PostgreSQL Setup

```bash
brew install postgresql@17
brew services start postgresql@17
createdb cyclowax_dashboard
```

Update `.env` with your local PostgreSQL credentials:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cyclowax_dashboard
DB_USERNAME=<your-macos-username>
DB_PASSWORD=
```

## 3. Database Migration & Seeding

```bash
php artisan migrate
php artisan db:seed
```

The `DatabaseSeeder` runs all sub-seeders in dependency order:
1. `UserSeeder` (team accounts)
2. `SupplyProfileSeeder` (supply chain profiles per category)
3. `ScenarioSeeder` (3 forecast scenarios with assumptions)
4. `ScenarioProductMixSeeder` (product mix shares from historical data)
5. `RegionalScenarioSeeder` (regional assumptions and mixes)
6. `DemandEventSeeder` (historical + planned demand events)

All seeders are idempotent and safe to run multiple times.

## 4. External Service Credentials

Add API credentials to `.env` for the sync pipeline:

```
SHOPIFY_STORE=<store>.myshopify.com
SHOPIFY_ACCESS_TOKEN=<token>
ODOO_URL=<url>
ODOO_API_KEY=<key>
KLAVIYO_API_KEY=<key>
```

## 5. Sync Pipeline

Run the full sync to populate data from external services:

```bash
php artisan sync:all --full
```

For incremental sync (daily):

```bash
php artisan sync:all
```

## 6. Build Frontend

```bash
npm run dev    # development with HMR
npm run build  # production build
```

## 7. Verify

The application is served by Laravel Herd at `https://cyclowax-dashboard.test`.

```bash
php artisan test --compact  # run test suite
```

## Data Migration (SQLite to PostgreSQL)

If migrating from an existing SQLite database, use the one-time migration seeder:

```bash
php artisan db:seed --class=SqliteToPostgresSeeder
```

This copies all data from `database/database.sqlite` to PostgreSQL in batches, with automatic sequence resets. No API calls needed.
