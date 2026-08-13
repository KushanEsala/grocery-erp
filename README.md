# Grocery ERP

A branch-aware grocery retail ERP built with Laravel 11, Next.js 16, and MySQL. It provides barcode POS, multiple units, weighted quantities, batch/expiry stock, FEFO dispatch, purchasing, returns, transfers, counts, cashier shifts, expenses, supplier balances, reports, audit logging, and role-based access. SQLite is not supported by this project.

The functional contract is in [`grocery_erp_requirements.md`](grocery_erp_requirements.md).

## Local setup

Start MySQL from XAMPP first. The local backend and automated tests use MySQL only; SQLite is not supported.

```powershell
Copy-Item backend\.env.example backend\.env
cd backend
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --host=127.0.0.1 --port=8008

cd ..\frontend
Copy-Item .env.example .env.local
npm ci
npm run dev
```

Use `http://localhost:3000` for local frontend development. The local API must be running at `http://127.0.0.1:8008/api`.

Create the databases once if they do not already exist:

```powershell
mysql -u root -e "CREATE DATABASE IF NOT EXISTS grocery_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS grocery_erp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

The production Docker defaults intentionally avoid the existing hire-purchase ports: the Grocery ERP frontend is exposed at `http://localhost:3002`, and its API at `http://localhost:8009/api`. Docker uses the existing host MySQL service through `host.docker.internal`, with a separate `grocery_erp` database and `grocery_erp_user` account.

Production is prepared for `https://pos.kushanesala.me`. For VPS specifications, DNS/HTTPS bootstrap, isolated database setup, safe Docker updates, and SSH-only Adminer access, see [`docs/VPS_DOCKER_DEPLOYMENT.md`](docs/VPS_DOCKER_DEPLOYMENT.md).

## Demo accounts

All seeded accounts use the password configured in `ERP_DEMO_PASSWORD`, defaulting to `password`.

| Role | Email |
|---|---|
| Super Admin | `admin@erp.com` |
| Manager | `manager@erp.com` |
| Cashier | `cashier@erp.com` |
| Storekeeper | `storekeeper@erp.com` |
| Accountant | `accountant@erp.com` |

## Verification

```powershell
cd backend
php artisan test

cd ..\frontend
npm run lint
npm run build
```

Legacy hire-purchase, repair/service, guarantor, installment, make/color, route-salesperson, and serial-number APIs are not registered in this application.
