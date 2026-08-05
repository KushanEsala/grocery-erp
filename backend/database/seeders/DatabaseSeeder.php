<?php

namespace Database\Seeders;

use App\Models\BranchDel;
use App\Models\Company;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        BranchDel::updateOrCreate(
            ['bccode' => 'HQ'],
            [
                'name' => 'Head Office',
                'phone' => '0112000000',
                'address' => 'Main Street, Colombo',
            ]
        );

        $company = Company::updateOrCreate(
            ['email' => 'info@company.com'],
            [
                'name' => 'Default Company',
                'address' => 'Colombo, Sri Lanka',
                'phone' => '0112000000',
                'currency' => 'LKR', 'timezone' => 'Asia/Colombo',
                'receipt_footer' => 'Thank you for shopping with us.',
            ]
        );
        BranchDel::where('bccode', 'HQ')->update(['company_id' => $company->id]);

        Store::updateOrCreate(
            ['name' => 'Main Store', 'BC' => 'HQ'],
            ['location' => 'Head Office', 'UID' => 'system']
        );

        Store::updateOrCreate(
            ['name' => 'Warehouse', 'BC' => 'HQ'],
            ['location' => 'Head Office Warehouse', 'UID' => 'system']
        );

        $adminRole = Role::updateOrCreate(
            ['name' => Role::SUPER_ADMIN],
            ['description' => 'Full system access. Permissions are implicit.']
        );

        $managerRole = Role::updateOrCreate(
            ['name' => 'Manager'],
            ['description' => 'Can manage daily operations.']
        );

        $cashierRole = Role::updateOrCreate(
            ['name' => 'Cashier'],
            ['description' => 'Can process sales and payments.']
        );

        $storekeeperRole = Role::updateOrCreate(
            ['name' => 'Storekeeper'],
            ['description' => 'Receives purchases and controls grocery inventory.']
        );

        $accountantRole = Role::updateOrCreate(
            ['name' => 'Accountant'],
            ['description' => 'Manages supplier payments, expenses, cash and financial reports.']
        );

        $modules = [
            'dashboard', 'pos', 'products', 'categories', 'brands', 'units', 'taxes',
            'customers', 'suppliers', 'stores', 'registers', 'purchases', 'purchase-returns',
            'inventory', 'transfers', 'adjustments', 'stock-counts', 'promotions',
            'sales', 'sales-returns', 'shifts', 'cash', 'expenses', 'supplier-payments',
            'accounts', 'reports', 'audit', 'settings',
        ];

        foreach ($modules as $module) {
            RolePermission::updateOrCreate(
                ['role_id' => $managerRole->id, 'module' => $module],
                [
                    'can_create' => true,
                    'can_read' => true,
                    'can_update' => true,
                    'can_delete' => false,
                    'BC' => 'HQ',
                    'UID' => 'system',
                ]
            );
        }

        foreach (['dashboard', 'pos', 'products', 'customers', 'sales', 'sales-returns', 'shifts'] as $module) {
            RolePermission::updateOrCreate(
                ['role_id' => $cashierRole->id, 'module' => $module],
                [
                    'can_create' => true,
                    'can_read' => true,
                    'can_update' => false,
                    'can_delete' => false,
                    'BC' => 'HQ',
                    'UID' => 'system',
                ]
            );
        }

        foreach (['products', 'categories', 'brands', 'units', 'suppliers', 'stores', 'purchases', 'purchase-returns', 'inventory', 'transfers', 'adjustments', 'stock-counts', 'reports'] as $module) {
            RolePermission::updateOrCreate(
                ['role_id' => $storekeeperRole->id, 'module' => $module],
                ['can_create' => true, 'can_read' => true, 'can_update' => true, 'can_delete' => false, 'BC' => 'HQ', 'UID' => 'system']
            );
        }

        foreach (['dashboard', 'supplier-payments', 'cash', 'expenses', 'accounts', 'reports', 'audit'] as $module) {
            RolePermission::updateOrCreate(
                ['role_id' => $accountantRole->id, 'module' => $module],
                ['can_create' => true, 'can_read' => true, 'can_update' => true, 'can_delete' => false, 'BC' => 'HQ', 'UID' => 'system']
            );
        }

        $this->upsertUser('admin', 'admin@erp.com', $adminRole->id);
        $this->upsertUser('manager', 'manager@erp.com', $managerRole->id);
        $this->upsertUser('cashier', 'cashier@erp.com', $cashierRole->id);
        $this->upsertUser('storekeeper', 'storekeeper@erp.com', $storekeeperRole->id);
        $this->upsertUser('accountant', 'accountant@erp.com', $accountantRole->id);

        $this->seedGroceryDefaults();
    }

    private function seedGroceryDefaults(): void
    {
        $now = now();
        $units = [
            ['code' => 'EA', 'name' => 'Each', 'decimal_places' => 0],
            ['code' => 'PK', 'name' => 'Pack', 'decimal_places' => 0],
            ['code' => 'CTN', 'name' => 'Carton', 'decimal_places' => 0],
            ['code' => 'KG', 'name' => 'Kilogram', 'decimal_places' => 3],
            ['code' => 'G', 'name' => 'Gram', 'decimal_places' => 0],
            ['code' => 'L', 'name' => 'Litre', 'decimal_places' => 3],
            ['code' => 'ML', 'name' => 'Millilitre', 'decimal_places' => 0],
        ];

        foreach ($units as $unit) {
            DB::table('units')->updateOrInsert(
                ['code' => $unit['code']],
                [...$unit, 'active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        DB::table('tax_rates')->updateOrInsert(
            ['name' => 'Zero Rated'],
            ['rate' => 0, 'inclusive' => true, 'active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        $storeId = DB::table('stores')->where('BC', 'HQ')->orderBy('id')->value('id');
        DB::table('registers')->updateOrInsert(
            ['branch_code' => 'HQ', 'code' => 'POS-01'],
            ['store_id' => $storeId, 'name' => 'Front Counter', 'active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        $walkIn = DB::table('customers')->where('Code', 'WALK-IN')->first();
        if (! $walkIn) {
            DB::table('customers')->insert([
                'Code' => 'WALK-IN', 'name' => 'Walk-in Customer', 'NIC' => 'WALK-IN-HQ',
                'phone' => null, 'address' => null, 'advance_balance' => 0,
                'BC' => 'HQ', 'UID' => 'system', 'created_at' => $now,
            ]);
        }

        foreach (['General', 'Utilities', 'Transport', 'Cleaning', 'Repairs'] as $name) {
            DB::table('expense_categories')->updateOrInsert(
                ['branch_code' => 'HQ', 'name' => $name],
                ['active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }

        $settings = [
            'shop_name' => 'Grocery ERP', 'currency' => 'LKR', 'timezone' => 'Asia/Colombo',
            'negative_stock' => 'false', 'require_open_shift' => 'true',
            'near_expiry_days' => '30', 'costing_method' => 'weighted_average',
            'customer_credit_enabled' => 'false', 'post_dated_cheques_enabled' => 'false',
            'accounting_enabled' => 'false', 'bilingual_receipts_enabled' => 'false',
        ];
        foreach ($settings as $key => $value) {
            DB::table('app_settings')->updateOrInsert(
                ['branch_code' => 'HQ', 'key' => $key],
                ['value' => $value, 'type' => is_numeric($value) ? 'number' : 'string', 'created_at' => $now, 'updated_at' => $now]
            );
        }

        foreach ([
            ['1000', 'Cash', 'asset'], ['1100', 'Inventory', 'asset'], ['1200', 'Customer Receivables', 'asset'],
            ['2000', 'Supplier Payables', 'liability'], ['4000', 'Sales Revenue', 'income'],
            ['5000', 'Cost of Goods Sold', 'expense'], ['6000', 'Operating Expenses', 'expense'],
        ] as [$code, $name, $type]) DB::table('chart_accounts')->updateOrInsert(
            ['branch_code' => 'HQ', 'code' => $code],
            ['name' => $name, 'type' => $type, 'active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        foreach ([
            'sale' => 'INV', 'return' => 'RET', 'shift' => 'SHF', 'purchase_order' => 'PO',
            'goods_receipt' => 'GRN', 'purchase_return' => 'PRN', 'transfer' => 'TRF',
            'adjustment' => 'ADJ', 'stock_count' => 'CNT', 'expense' => 'EXP', 'supplier_payment' => 'SPY', 'customer_payment' => 'CPY',
        ] as $type => $prefix) {
            DB::table('document_sequences')->updateOrInsert(
                ['branch_code' => 'HQ', 'document_type' => $type],
                ['prefix' => $prefix, 'next_number' => 1, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    private function upsertUser(string $username, string $email, int $roleId): void
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->username = $username;
        $user->role_id = $roleId;
        $user->BC = 'HQ';
        $user->password = Hash::make((string) env('ERP_DEMO_PASSWORD', 'password'));

        $user->save();
    }
}
