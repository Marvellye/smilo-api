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

🔒 = requires `Authorization: Bearer <JWT>`.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/health` | No | Health check |
| POST | `/api/auth/register` | No | Register (buyer/seller) — returns JWT |
| POST | `/api/auth/login` | No | Login — returns JWT |
| GET | `/api/auth/me` | 🔒 | Current user + shop info |
| PUT | `/api/auth/profile` | 🔒 | Update name/phone |
| POST | `/api/auth/become-seller` | 🔒 | Open a shop (shop_name, location, phone) |
| GET | `/api/products` | No | List (filters: `category`, `price_min/max`, `q`, `sort`, `page`, `limit`) |
| GET | `/api/products/mine` | 🔒 | Authenticated seller's own listings |
| GET | `/api/products/:id` | No | Single product + seller info |
| POST | `/api/products` | 🔒 seller | Post an ad |
| PUT | `/api/products/:id` | 🔒 owner | Edit own ad (incl. `status`: active/sold/removed) |
| DELETE | `/api/products/:id` | 🔒 owner | Remove own ad |
| GET | `/api/categories` | No | Distinct categories |
| GET | `/api/sellers` | No | All sellers + product counts |
| GET | `/api/sellers/:id` | No | Seller + their products |
| POST | `/api/messages` | No | Contact seller about a product |
| GET | `/api/messages/inbox` | 🔒 seller | Buyer messages received |
| GET | `/api/messages/sent` | 🔒 | Messages the user sent |
| GET | `/api/messages?product_id=X` | No | Messages for one product |
| PUT | `/api/messages/:id/read` | No | Mark message read |
| GET | `/api/products/:id/reviews` | No | Reviews + rating breakdown |
| POST | `/api/products/:id/reviews` | No | Submit review (updates product rating) |
| POST | `/api/reviews/:id/helpful` | No | Mark review helpful |

## Database

Auto-migrated and seeded on first request. Seed data mirrors the frontend product catalog.
