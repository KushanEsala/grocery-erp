# Grocery Shop ERP Requirements

## 1. Purpose

Build a grocery shop ERP by reusing the existing Hire Purchase ERP's core architecture, shared components, interaction patterns, and visual design. The new system must contain only features relevant to grocery retail, purchasing, inventory, cashier operations, basic accounting, and management reporting.

This document defines the agreed functional scope. It is a requirements specification, not an implementation plan.

## 2. Product Goals

- Provide a fast, keyboard-friendly point-of-sale (POS) workflow for grocery cashiers.
- Maintain accurate stock by branch, store/location, batch, and expiry date.
- Support barcode-based products, weighted products, multiple selling units, packs, and unit conversions.
- Manage purchasing, goods receiving, supplier balances, sales returns, and purchase returns.
- Give owners and managers reliable daily sales, profit, stock, expiry, cash, expense, and tax information.
- Preserve the current ERP's security, branch isolation, auditability, API structure, responsive UI, and print/export patterns.
- Keep daily operations simple enough for a small or medium grocery shop while allowing multiple branches.

## 3. Existing Core and UI to Retain

The grocery ERP will keep and adapt the following parts of the current application:

- Laravel 11 REST API, PHP 8.2+, Eloquent ORM, migrations, services, controllers, and request validation.
- Laravel Sanctum authentication and role-based CRUD permissions.
- Next.js 16, React 19, TypeScript, Tailwind CSS, Axios, Lucide icons, and the existing API client approach.
- MySQL/MariaDB database and Docker-based local/deployment setup.
- Existing responsive application shell: collapsible sidebar, mobile navigation, authenticated header, page containers, cards, forms, tables, dialogs, alerts, loading states, and empty states.
- Existing CRUD workspace and operation-page patterns.
- Existing color palette, typography, spacing, controls, validation presentation, and responsive breakpoints.
- Branch-scoped data access using the authenticated user's branch.
- Existing document print/PDF conventions, adapted for grocery receipts and reports.
- Existing transaction-service pattern with database transactions and stock/accounting posting.
- Existing backup/restore capability, subject to the authorization rules in this document.

Branding text and icons must change from **Hire Purchase ERP** to **Grocery ERP** or the configured shop name. The shared UI should be reused rather than redesigned.

## 4. Users and Roles

The system must include these default roles. Permissions remain configurable per module and per CRUD action.

| Role | Main responsibilities |
|---|---|
| Super Admin | System configuration, branches, users, roles, permissions, backups, and all data |
| Owner / Administrator | Full business visibility, prices, costs, profit, reports, and approvals |
| Branch Manager | Branch operations, stock, purchasing, returns, expenses, day-end, and branch reports |
| Cashier | POS sales, permitted discounts, suspended sales, customer selection, payments, and returns subject to approval |
| Storekeeper | Receiving, batch/expiry capture, stock counts, adjustments, transfers, and reorder review |
| Accountant | Supplier payments, expenses, cash/bank records, tax and financial reports |

Minimum permission actions are `can_create`, `can_read`, `can_update`, `can_delete`, plus named approval permissions for sensitive actions. Cost price, profit, manual discount, price override, stock adjustment, void, refund, and day reopening must be independently restricted.

## 5. Navigation and Module Scope

The sidebar must use the current expandable navigation design with the following grocery-specific structure:

1. **Main**
   - Dashboard
   - Point of Sale
2. **Master Data**
   - Products
   - Categories
   - Brands
   - Units of Measure
   - Suppliers
   - Customers
   - Stores / Stock Locations
3. **Purchases**
   - Purchase Orders
   - Goods Receipts
   - Purchase Returns
   - Supplier Payments
4. **Inventory**
   - Stock Levels
   - Batch & Expiry Tracking
   - Stock Transfers
   - Stock Adjustments
   - Stock Counts
   - Reorder Alerts
5. **Sales**
   - Sales History
   - Sales Returns
   - Quotations / Held Sales (optional Phase 2)
   - Customer Payments (only when customer credit is enabled)
6. **Cash & Expenses**
   - Cashier Shifts
   - Cash In / Cash Out
   - Expenses
   - Banking
7. **Promotions**
   - Discounts and Promotions
   - Price Lists (optional Phase 2)
8. **Reports**
   - Sales, Purchase, Inventory, Profit, Cash, Expense, Tax, and Audit reports
9. **Administration**
   - Company / Shop Settings
   - Branches
   - Users
   - Roles & Permissions
   - Number Sequences
   - Backups

Items hidden by permissions must not appear in navigation. API authorization must still be enforced independently of the UI.

## 6. Functional Requirements

### 6.1 Authentication, Security, and Audit

- Users must sign in with email/username and password and sign out securely.
- Every operational record must be branch-scoped and store its creator, creation time, and last updater where applicable.
- Users may access only permitted branches and modules.
- Sensitive operations must record an audit entry containing user, timestamp, branch, action, record, reason, and before/after values where applicable.
- Audited actions include price changes, discounts above the user's limit, sale voids, returns, refunds, stock adjustments, reopened shifts/days, supplier payment cancellation, and master-data deletion/deactivation.
- Posted financial or stock transactions must not be hard-deleted. They must be voided/reversed with a reason and permission.
- Login, API, and validation behavior should follow the existing core implementation.

### 6.2 Company, Branch, Store, and Register Setup

- Configure shop name, logo, address, phone, email, tax registration number, currency, timezone, receipt footer, and default tax behavior.
- Support one or more branches.
- Support one or more stock locations/stores per branch, such as shop floor, warehouse, and damaged stock.
- Configure POS registers/terminals and associate each with a branch and default stock location.
- Configure document number sequences by branch for sales, returns, purchase orders, receipts, transfers, adjustments, payments, and shifts.
- Branch configuration must support active/inactive status without deleting historical data.

### 6.3 Product and Barcode Management

Each product must support:

- Unique internal SKU/item code.
- Product name, optional local-language name, description, category, subcategory, and brand.
- One primary barcode and multiple alternate barcodes.
- Base unit and selling/purchasing units such as each, pack, carton, kilogram, gram, litre, and millilitre.
- Unit conversion factors, for example `1 carton = 12 packs` and `1 pack = 6 each`.
- Standard purchase cost, latest purchase cost, average cost, retail price, and optional wholesale/member price.
- Tax category/rate, tax-inclusive or tax-exclusive pricing, and tax-exempt status.
- Reorder level, preferred supplier, minimum order quantity, and optional maximum stock level.
- Batch tracking toggle and expiry tracking toggle.
- Weighted-item toggle, weight unit, and optional scale-barcode parsing rule.
- Allow/disallow decimal quantity based on unit/product type.
- Product image (optional), shelf/location reference, and notes.
- Active/inactive status. Products with transaction history must be deactivated, not deleted.

Barcode values must be unique. Product search must work by barcode, SKU, name, category, and brand. Serial-number tracking from the current ERP is not required for grocery products.

### 6.4 Supplier Management

- Store supplier code, name, contact person, phone, email, address, tax number, credit limit, payment terms, opening balance, and status.
- Show supplier purchase history, payments, returns, outstanding invoices, and current balance.
- Support cash and credit purchases.
- Prevent new credit purchases or warn authorized users when the configured supplier/branch rule is exceeded.

### 6.5 Customer Management

- A default **Walk-in Customer** must be available for normal POS sales.
- Registered customers may store code, name, phone, email, address, tax number, loyalty/member number, credit limit, opening balance, and status.
- Customer credit must be optional and disabled by default for cashier roles.
- If credit is enabled, show invoices, payments, returns, outstanding balance, and credit-limit warnings.
- Loyalty points, gift cards, and store credit are Phase 2 features and must not block the first release.

### 6.6 Point of Sale (POS)

The POS is a critical screen and must be optimized for barcode scanners, keyboards, and touch screens.

- Start a sale by scanning a barcode or searching by SKU/name.
- Repeated scans increase quantity; quantity may be edited subject to product rules.
- Support regular, pack/carton, and decimal/weighted quantities.
- Select the correct selling unit and automatically convert stock to the base unit.
- Display product, unit, quantity, unit price, line discount, tax, line total, and available stock.
- Use batch selection rules and default to FEFO for expiry-tracked items.
- Block sale of expired batches and insufficient stock. Negative stock is disabled by default.
- Support line and invoice discounts by amount or percentage, with configurable permission/approval limits.
- Apply active promotions automatically and clearly show the applied rule and savings.
- Support cash, card, bank transfer, mobile/QR payment, store credit, and split payments.
- Calculate balance/change for cash payments.
- Allow customer selection while defaulting to Walk-in Customer.
- Hold/suspend a cart and resume it on the same branch.
- Remove a line or void the cart with appropriate permissions.
- Complete the sale atomically: save header/lines/payments, reduce stock, record cost, post accounts if enabled, and generate a receipt number.
- Print/reprint an 80 mm thermal receipt and provide an A4 invoice layout where required.
- Receipt must show shop details, invoice number, date/time, cashier, register, items, units, quantities, prices, discounts, tax, payment breakdown, total, tendered amount, change, and configurable footer.
- Reprints must be marked **REPRINT** and audited.
- A completed sale cannot be edited directly; corrections use return, refund, or authorized void workflows.
- The POS should remain usable at common desktop/tablet sizes and provide clear scanner focus and keyboard shortcuts.

Recommended shortcuts: focus item search, change quantity, apply discount, hold/resume sale, take payment, and print receipt. Exact keys will be finalized during implementation.

### 6.7 Promotions and Pricing

- Create scheduled promotions with start/end date and time, active status, branch applicability, and priority.
- Support percentage discount, fixed discount, promotional selling price, buy-X-get-Y, and quantity-break pricing.
- Target promotions by product, category, brand, or basket subtotal.
- Define whether promotions can stack; the default is no stacking, with the best valid promotion applied.
- Manual price override requires a dedicated permission and an audit reason.
- Promotion evaluation must be deterministic and testable.
- Coupons, complex loyalty tiers, and supplier-funded promotion settlement are Phase 2.

### 6.8 Purchasing and Goods Receiving

- Create draft purchase orders for a supplier, branch, and receiving store.
- Add products by search/barcode with purchase unit, ordered quantity, free quantity, cost, discount, tax, and expected date.
- Purchase orders support draft, approved, partially received, received, cancelled, and closed statuses.
- Approval may be required based on role or amount threshold.
- Receive stock against a purchase order or through a permitted direct goods receipt.
- Allow partial receipts and show ordered, previously received, remaining, accepted, and rejected quantities.
- Capture supplier invoice number/date and prevent accidental duplicate supplier invoices.
- Capture batch/lot number, manufactured date (optional), expiry date, purchase cost, and selling price for tracked products.
- Update inventory and product latest/average costs only when a receipt is posted.
- Support cash/credit purchase classification and supplier balance posting.
- Print/export purchase orders and goods receipt notes.
- Posted receipts must be reversed through a controlled return/cancellation flow, not edited destructively.

### 6.9 Purchase Returns

- Return received products to a supplier by referencing a goods receipt where possible.
- Capture return reason, product, unit/base quantity, batch, cost, tax, and notes.
- Prevent returning more than the available eligible quantity.
- Reduce stock from the selected store/batch and update the supplier account through a debit note or balance reduction.
- Support draft and posted states; only posted returns affect stock and accounts.

### 6.10 Inventory and Stock Control

- Display stock on hand by branch, store, product, base unit, batch, and expiry date.
- Maintain an immutable stock-movement ledger with transaction type, reference, in/out quantity, store, batch, unit cost, user, and timestamp.
- Use FEFO (first-expiry-first-out) as the default issue suggestion for expiry-tracked products and FIFO/average-cost policy for valuation as configured.
- Provide near-expiry alerts using configurable day bands, expired stock lists, and batch traceability.
- Create stock transfers between stores or branches with requested, dispatched, in-transit, received, and cancelled statuses.
- Inter-branch transfer receipt must be confirmed by an authorized destination user.
- Support stock adjustments for damage, spoilage, expiry, theft, data correction, and opening balance; require a reason and authorization.
- Support full and cycle stock counts with count sheets, frozen snapshot, variance calculation, review, and posting.
- Generate reorder alerts from stock level, reorder point, pending purchase orders, and recent sales.
- Optionally suggest purchase quantities; automatic purchase-order creation is Phase 2.
- Show stock valuation using the selected costing method and preserve the transaction cost used for historical gross-profit reporting.

### 6.11 Sales Returns and Refunds

- Prefer return against the original sales invoice and validate the refundable quantity.
- Capture return reason, condition, quantity, and target location (saleable, damaged, or expired).
- Return saleable stock to the correct batch/location only after authorization.
- Refund using cash, original payment method, store credit, or exchange according to permissions.
- Support partial returns and exchanges.
- Prevent total returned quantity/value from exceeding the original eligible sale.
- Generate and print a return/refund receipt.
- All returns, refunds, and no-receipt returns must be audited; no-receipt return is disabled by default.

### 6.12 Cashier Shifts and Day-End

- A cashier must open a register shift with an opening float before accepting POS payments, unless the branch disables this rule.
- Track sales and cash movements per branch, register, cashier, and shift.
- Support authorized cash drops, cash in, and cash out with reason/reference.
- At shift close, capture counted cash by denomination or total, expected cash, variance, notes, and manager approval when outside tolerance.
- Closed shifts are read-only. Reopening requires a specific permission and audit record.
- Day-end summary must consolidate shifts, sales, refunds, payment methods, expenses, cash movements, and variances.
- Prevent duplicate day close. Reversal/reopen requires owner/admin authorization and a reason.

### 6.13 Supplier and Customer Payments

- Record supplier payments by cash, cheque, card, bank transfer, or other configured method.
- Allocate payments to one or more outstanding supplier invoices; allow controlled unallocated advances.
- If customer credit is enabled, record and allocate customer receipts similarly.
- Store payment date, reference, bank/cash account, amount, allocations, notes, branch, and user.
- Posted payments affect balances and cannot be directly edited; use cancellation/reversal with permission.
- Full cheque-clearing lifecycle may be retained only if the business accepts post-dated cheques. Otherwise it is excluded from Release 1.

### 6.14 Expenses, Cash, and Basic Accounting

- Configure expense categories and cash/bank accounts.
- Record branch expenses with date, category, payee, amount, payment method/account, reference, notes, and optional attachment.
- Support approval thresholds and posted/cancelled status.
- Record bank deposits, withdrawals, and transfers when the accounting module is enabled.
- Retain the existing chart-of-accounts and transaction posting core where practical.
- Automatically post sales, tax, cost of goods sold, inventory, purchases, supplier balances, customer balances, cash/bank, returns, and expenses.
- Release 1 accounting is operational/basic accounting. Payroll, fixed assets, manufacturing, and advanced budgeting are excluded.

### 6.15 Dashboard

Dashboard data must respect the user's branch and permissions. It should provide:

- Today's sales, transaction count, average basket value, gross profit, and refund total.
- Sales by payment method and hourly sales trend.
- Top-selling and slow-moving products.
- Low-stock, out-of-stock, near-expiry, and expired counts.
- Purchase and supplier outstanding summaries.
- Cashier/shift status and cash variance alerts.
- Recent sales, purchases, returns, and stock adjustments.
- Quick actions for POS, goods receipt, product creation, stock adjustment, and expense entry based on permission.
- Date and branch filters where the user has multi-branch access.

### 6.16 Reports

All reports must support relevant filters, pagination, totals, print-friendly output, and CSV/PDF export. Financial values must use the configured currency and rounding rules.

Required Release 1 reports:

- Daily, date-range, branch, cashier, register, category, product, and payment-method sales.
- Sales detail, invoice register, cancelled/voided sales, discounts, returns, and refunds.
- Gross profit by invoice, product, category, branch, and date range.
- Current stock, stock valuation, stock movement/bin card, and negative-stock exception report.
- Batch, expiry, near-expiry, and expired-stock reports.
- Low-stock, out-of-stock, reorder, fast-moving, slow-moving, and dead-stock reports.
- Purchase order, goods receipt, purchase detail, purchase return, and supplier purchase reports.
- Supplier balances, aging, payment history, and statement.
- Customer balances, aging, payment history, and statement when credit sales are enabled.
- Cashier shift, payment-method reconciliation, cash in/out, day-end, and variance reports.
- Expense report by category, branch, payee, and period.
- Tax sales, tax purchases, and net tax summary.
- Audit log report for sensitive actions.

Optional Phase 2 reports include promotion effectiveness, basket analysis, loyalty activity, forecasted demand, and comparative multi-period dashboards.

### 6.17 Notifications and Alerts

- Show in-app alerts for low stock, out of stock, near expiry, expired products, overdue supplier balances, unclosed shifts, and stock-count variances.
- Alerts must link to the relevant filtered workspace.
- Email/SMS/WhatsApp notifications are out of scope for Release 1 unless separately approved.

### 6.18 Backup and Data Management

- Authorized administrators can create, download, list, and restore system backups using the retained backup core.
- Restore must require explicit confirmation, a recent backup, and Super Admin permission.
- Provide CSV import templates for products, barcodes, prices, suppliers, customers, and opening stock.
- Imports must validate all rows and present an error report before posting changes.
- Provide CSV export for master data and report results.

## 7. Key Business Rules

1. Every transaction belongs to exactly one branch; inventory transactions also belong to a store/location.
2. Stock-changing and financial operations must be atomic database transactions.
3. Negative stock is blocked by default and can only be enabled through an explicit global setting and permission.
4. Expired stock cannot be sold. Near-expiry stock may be sold unless a configured rule blocks it.
5. The base-unit quantity is the inventory source of truth; alternate-unit transactions are converted and retain their entered unit/conversion for audit and printing.
6. Product barcode, SKU, document number within its sequence scope, and supplier invoice uniqueness rules must be enforced in the database where practical.
7. Monetary calculations use decimal arithmetic, configured currency precision, and consistent line/header rounding.
8. Completed or posted documents are immutable. Corrections require a documented reversal, return, or cancellation.
9. Historical transaction lines retain product description, unit, price, tax, discount, cost, and conversion snapshots even if master data later changes.
10. Gross profit uses the cost captured at the time of sale, not the product's current cost.
11. FEFO selection must never silently substitute an expired batch.
12. Permission checks are mandatory on both frontend actions and backend endpoints.

## 8. Required Core Data Entities

The exact table names may follow existing conventions, but the domain must include:

- companies, branches, stores, registers
- users, roles, permissions, user-branch access
- categories, brands, units, products, product_barcodes, product_units, tax_rates
- suppliers, customers
- product_batches and stock_movements
- stock_transfers and transfer_lines
- stock_adjustments and adjustment_lines
- stock_counts and count_lines
- purchase_orders and purchase_order_lines
- goods_receipts and receipt_lines
- purchase_returns and purchase_return_lines
- sales, sale_lines, sale_payments, suspended_sales
- sales_returns and sales_return_lines
- promotions and promotion_rules
- supplier_payments and allocations
- customer_payments and allocations (only if credit is enabled)
- cashier_shifts and cash_movements
- expenses and expense_categories
- accounts and accounting_transactions (when accounting is enabled)
- day_ends, audit_logs, number_sequences, settings, and backups

Tables must use foreign keys, appropriate unique constraints, branch/date/status indexes, and decimal types for money and quantities. Quantity precision must support weighted goods.

## 9. UI and UX Requirements

- Keep the existing UI system and page layout; adapt labels, navigation, icons, cards, and forms to grocery operations.
- Use consistent list/detail/create workflows and reuse current components before adding new ones.
- Every data table should support search, appropriate filters, pagination, loading/empty/error states, and permission-aware actions.
- Forms must provide inline validation, prevent accidental duplicate submission, and warn about unsaved changes.
- Destructive or posting actions require a confirmation dialog that explains the effect.
- Use status badges consistently for draft, posted, cancelled, paid, partial, expired, and active/inactive states.
- POS should favor speed and density; administrative screens should preserve the existing spacious CRUD design.
- Interfaces must be responsive for desktop and tablet. Mobile support is required for lookup/reporting and simple operations, but full cashier efficiency is targeted at tablet/desktop.
- Receipt and report layouts must be printable without sidebar/header chrome.
- Basic accessibility is required: labels, keyboard navigation, visible focus, semantic controls, sufficient contrast, and clear error text.

## 10. Non-Functional Requirements

### 10.1 Performance

- Product lookup by exact barcode should normally return within 500 ms on the local business network.
- POS add-item interactions should feel immediate after the product response.
- Standard paginated lists and dashboard requests should normally complete within 2 seconds under expected shop load.
- Use database indexes for barcode, SKU, branch, store, batch, expiry, document number, status, and transaction date searches.
- Large exports should use streamed/chunked processing or background jobs where needed.

### 10.2 Reliability and Data Integrity

- Use database transactions and row locking for sale completion, receiving, return, transfer, payment, and stock adjustment posting.
- Repeated client submissions must not create duplicate completed transactions; use idempotency or server-side duplicate protection for critical posts.
- Maintain referential integrity and never orphan stock or financial lines.
- Log application errors without exposing secrets or stack traces to end users.

### 10.3 Security

- Validate and authorize every API request server-side.
- Hash passwords using Laravel defaults and use secure Sanctum token/session handling.
- Apply rate limiting to authentication and sensitive endpoints.
- Protect against mass assignment, SQL injection, XSS, CSRF where applicable, and insecure direct-object access.
- Secrets must remain in environment configuration and never be committed.
- Uploaded files must be type/size validated and stored outside executable paths.

### 10.4 Maintainability and Testing

- Preserve the decoupled API/frontend architecture and typed frontend service modules.
- Add feature tests for all transaction workflows and authorization boundaries.
- Add unit tests for unit conversion, tax, discount, promotion, totals, costing, FEFO, expiry, and payment calculations.
- Required verification commands remain `php artisan test`, `npm run lint`, and `npm run build`.
- Database migrations and seeders must provide default roles, permissions, Walk-in Customer, units, payment methods, and base settings.

## 11. Explicitly Excluded from the Grocery ERP

The following current-system features are not relevant and must not appear in the grocery navigation, permissions, APIs, or new data model unless separately approved:

- Hire-purchase agreements, installment schedules/payments, HP returns, guarantors, schemas, and HP-to-cash conversion.
- Repair/service tickets, technician assignment, service dispatch, service invoicing, and service returns.
- Product makes/models and colors.
- Item serial-number capture, serial movement, and serial edit audit.
- Salesperson routes/areas and collection routes unless later required for delivery operations.
- Cheque lifecycle outside the configurable post-dated supplier-cheque workflow.
- Manufacturing, recipes/bill of materials, payroll, HR, fixed assets, e-commerce, delivery fleet management, and full CRM.
- Complex loyalty, gift cards, coupons, demand forecasting, and offline multi-device synchronization in Release 1.

Existing source code for excluded modules may be used as a reference during migration, but must not remain accessible in the grocery application.

## 12. Release Priorities

### Release 1 — Required MVP

- Authentication, branch scoping, roles, and permissions
- Shop/branch/store/register settings
- Products, categories, brands, units, barcodes, tax, suppliers, and Walk-in Customer
- POS with barcode scan, multiple units, discounts, tax, split payment, hold/resume, and receipt printing
- Purchases, goods receiving, batch/expiry capture, and purchase returns
- Stock levels, movements, FEFO, transfers, adjustments, counts, and reorder/expiry alerts
- Sales returns/refunds
- Cashier shifts, cash movement, day-end, and expenses
- Supplier payments and balances
- Dashboard and all required operational reports
- Audit log, backups, imports/exports, automated tests, and production configuration

### Release 2 — Optional

- Customer credit statement aging and automated reminder workflows beyond the implemented configurable credit-sale and repayment ledger
- Loyalty points, membership pricing, gift cards, coupons, and store credit
- Advanced promotions and promotion analysis
- Suggested purchasing and demand forecasting
- Direct weighing-scale hardware integration beyond the implemented configurable embedded-weight barcode parsing
- Email/SMS/WhatsApp notifications
- E-commerce, delivery, and third-party accounting/payment integrations
- Robust offline POS synchronization

## 13. Release 1 Acceptance Criteria

Release 1 is acceptable when:

1. An administrator can configure a branch, store, register, users, roles, products, units, barcodes, suppliers, taxes, prices, and opening stock.
2. A cashier can open a shift, scan regular and weighted/unit-converted products, apply permitted discounts, accept split payment, complete a sale, and print/reprint a correct receipt.
3. Completing, returning, or voiding a sale produces correct and auditable stock, payment, tax, and profit results without duplicate posting.
4. A storekeeper can create/receive purchases with batches and expiry dates, transfer stock, count stock, post authorized variances, and identify low/near-expiry stock.
5. Expired or insufficient stock cannot be sold under default configuration, and FEFO selects the correct eligible batch.
6. A manager can process purchase returns, sales refunds, supplier payments, expenses, shift close, and day-end within configured permissions.
7. Owner/manager reports reconcile to underlying sales, purchases, stock movements, payments, shifts, expenses, and returns for the same filters.
8. Branch users cannot access another branch's data without explicit multi-branch authorization.
9. All sensitive actions are permission-protected and visible in the audit report.
10. Backend tests pass, frontend lint/build pass, backup/restore is verified, and critical POS/purchase/stock workflows are tested end to end.

## 14. Decisions to Confirm Before Implementation

The following business choices affect configuration or Release 1 scope and should be confirmed before development starts:

- Single branch or multi-branch at launch.
- Currency, tax rules, tax-inclusive/exclusive prices, and required tax invoice format.
- Whether customer credit, post-dated cheques, and full chart-of-accounts functionality are required in Release 1.
- Inventory costing method: weighted average (recommended) or FIFO.
- Supported receipt printers, barcode scanners, weighing scales, cash drawers, and label printers.
- Required scale-barcode format for weighted products.
- Whether negative stock is ever permitted.
- Discount and approval limits by role.
- Required import source and opening-balance cutover process.
- Required languages and whether product names/receipts must be bilingual.
- Backup destination, retention period, and restore responsibility.

---

**Baseline:** Existing Hire Purchase ERP repository in this workspace.  
**Target:** Grocery Shop ERP with the same core architecture and UI design, reduced to grocery-relevant modules and extended only where grocery retail requires it.

## 15. Implementation and Verification Status

**Release 1 status: COMPLETE — verified 5 August 2026**

The Grocery ERP is implemented as the separate repository `E:\freelance\grocery-erp`. Runtime, development, and automated tests use **MySQL only**; SQLite is not configured or used.

| Acceptance area | Status | Verified implementation |
|---|---|---|
| Administration and master data | PASS | Branches, stores, registers, users, action-level roles/permissions, grocery-only suppliers without supplier types, product/category/brand/unit/tax CRUD, barcodes, prices, customers, promotions, document numbering, company settings, and opening stock |
| POS and cashier workflow | PASS | Scanner/search flow, weighted/decimal quantities, configurable scale barcodes, unit conversion, promotion/discount and inclusive/exclusive tax calculations, customer selection, cash/card/bank/mobile/credit/store-credit payments, split payment, hold/resume, checkout-only full screen, shift requirement, atomic checkout, and 80 mm print/reprint receipt |
| Sale correction controls | PASS | Completed sales are immutable; return/refund and authorized void workflows reverse stock and write audit records |
| Purchasing | PASS | Purchase orders with validated branch product/unit foreign keys, scanner/search picker across 1,000 active products, correct purchase/selling price defaults, approval, direct or PO-linked goods receipts, partial receipt quantities, supplier invoices, batches, expiry, free/rejected quantities, weighted-average cost, and purchase returns |
| Inventory | PASS | Store stock, immutable movements, opening/adjustment reasons, FEFO, expired-stock blocking, transfers with destination receipt, low-stock/expiry alerts, cycle/full counts, physical quantity entry, and variance posting |
| Cash and supplier operations | PASS | Shift open/close and variance, cash in/out/drop, expenses, supplier balances/payments, configurable customer credit sales and repayments with overpayment-to-store-credit handling, post-dated supplier cheques with status lifecycle, and chart-of-accounts maintenance |
| Reports and output | PASS | Dashboard and sales/profit/inventory/expiry/supplier/shift/expense/audit reports with search, CSV export, and print/PDF output |
| Security and isolation | PASS | Sanctum authentication, branch scoping, grocery module/action permissions, Super Admin controls, audit trail, and excluded HP/service endpoints returning 404 |
| Backup and restore | PASS | Grocery-table ZIP/CSV backups, download/history, operational refresh, validated restore, and automatic pre-restore safety backup |
| Build and automated verification | PASS | MySQL migrations/seeding, backend feature tests, frontend ESLint/TypeScript/production build, and live browser checks |

### Verification evidence

- Backend: `9` tests passed with `100` assertions against the dedicated MySQL test database, including invalid purchase-order picker values, price persistence, company features, tax/numbering configuration, credit sales/repayments, and cheque lifecycle coverage.
- Frontend: ESLint passed, TypeScript passed, and the Next.js production build generated all `43` expected Grocery ERP routes.
- Live UI: login, correct product purchase/selling prices, searchable barcode-aware purchase picker, modal-local validation warnings, POS totals and checkout-only full screen, company settings, grocery-only navigation, role permissions, and backup controls were inspected in the browser.
- Backup integrity: automated restore verification changes live data, restores the selected snapshot, and confirms the original value returns; the archive also confirms excluded hire-purchase data is absent.

### Release 1 configuration assumptions

- Weighted-average inventory costing is enabled.
- Negative stock and sales from expired batches are blocked.
- Customer credit, post-dated supplier cheques, chart of accounts, bilingual receipts, embedded-weight scale barcodes, cash-drawer commands, and receipt/label-printer names are deployment settings. They are disabled by default and can be enabled independently in Company Settings.
- The seeded company uses `LKR` and `Asia/Colombo`; company identity, tax, receipt footers, document prefixes/next numbers, and branch settings are editable in the administration UI.
