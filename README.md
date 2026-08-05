# Grocery ERP

A branch-aware grocery retail ERP built with Laravel 11, Next.js 16, and MySQL. It provides barcode POS, multiple units, weighted quantities, batch/expiry stock, FEFO dispatch, purchasing, returns, transfers, counts, cashier shifts, expenses, supplier balances, reports, audit logging, and role-based access. SQLite is not supported by this project.

The functional contract is in [`grocery_erp_requirements.md`](grocery_erp_requirements.md).

## Local setup

```powershell
Copy-Item backend\.env.example backend\.env
cd backend
composer install
php artisan key:generate
php artisan migrate:fresh --seed

cd ..\frontend
npm ci
npm run dev
```

The frontend defaults to `http://localhost:3000`; the Docker API is exposed at `http://localhost:8008/api`.

Create `frontend/.env.local` when needed:

```env
NEXT_PUBLIC_API_URL=http://localhost:8008/api
NEXT_PUBLIC_APP_URL=http://localhost:3000
```

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
