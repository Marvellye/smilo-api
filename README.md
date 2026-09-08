# Smilo API

Backend API for the Smilo Marketplace, built with [FlightPHP v3](https://docs.flightphp.com/en/v3/).

## Requirements

- PHP 8.1+ (with `pdo_mysql` or `pdo_sqlite` extension)
- Composer
- MySQL (default) or SQLite

## Quick start

```bash
composer install
cp .env.example .env  # or edit .env directly
php -S localhost:8000 -t public
```

## Configuration

Edit `.env` to switch between MySQL and SQLite:

```env
# "mysql" or "sqlite"
DB_DRIVER=mysql

# MySQL
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=smilo
DB_USER=root
DB_PASS=

# SQLite (fallback)
DB_SQLITE_PATH=storage/database/smilo.db
```

## Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/health` | Health check |
| GET | `/api/products` | List products (filters: `category`, `price_min`, `price_max`, `q`, `sort`, `page`, `limit`) |
| GET | `/api/products/:id` | Single product with seller info |
| GET | `/api/categories` | Distinct product categories |
| GET | `/api/sellers` | List all sellers with product counts |
| GET | `/api/sellers/:id` | Single seller + their products |

## Database

Auto-migrated and seeded on first request. Seed data mirrors the frontend product catalog.
