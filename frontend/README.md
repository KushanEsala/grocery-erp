# Grocery ERP Frontend

Next.js frontend for Grocery ERP cashier, stock, purchasing, reporting, and administration screens.

## Local development

Start MySQL and the Laravel API first. Local development expects the API URL configured in `.env.local` (the example uses `http://127.0.0.1:8008/api`). Production Docker builds receive the public API URL from the repository root `.env` file.

```powershell
Copy-Item .env.example .env.local
npm ci
npm run dev
```

Open `http://localhost:3000`.

## Verification

```powershell
npm run lint
npm run build
```
