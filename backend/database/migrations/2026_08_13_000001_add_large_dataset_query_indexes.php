<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['branch_code', 'active', 'name'], 'products_branch_active_name_idx');
            $table->index(['branch_code', 'active', 'sku'], 'products_branch_active_sku_idx');
        });
        Schema::table('goods_receipts', fn (Blueprint $table) => $table->index(['branch_code', 'received_at', 'status'], 'receipts_branch_date_status_idx'));
        Schema::table('purchase_returns', fn (Blueprint $table) => $table->index(['branch_code', 'created_at'], 'purchase_returns_branch_date_idx'));
        Schema::table('sales_returns', fn (Blueprint $table) => $table->index(['branch_code', 'created_at'], 'sales_returns_branch_date_idx'));
        Schema::table('cash_movements', fn (Blueprint $table) => $table->index(['branch_code', 'created_at', 'type'], 'cash_movements_branch_date_type_idx'));
        Schema::table('grocery_expenses', fn (Blueprint $table) => $table->index(['branch_code', 'expense_date', 'status'], 'expenses_branch_date_status_idx'));
        Schema::table('supplier_account_entries', fn (Blueprint $table) => $table->index(['branch_code', 'supplier_id', 'entry_date'], 'supplier_entries_lookup_idx'));
        Schema::table('customer_account_entries', fn (Blueprint $table) => $table->index(['branch_code', 'customer_id', 'entry_date'], 'customer_entries_lookup_idx'));
    }

    public function down(): void
    {
        Schema::table('customer_account_entries', fn (Blueprint $table) => $table->dropIndex('customer_entries_lookup_idx'));
        Schema::table('supplier_account_entries', fn (Blueprint $table) => $table->dropIndex('supplier_entries_lookup_idx'));
        Schema::table('grocery_expenses', fn (Blueprint $table) => $table->dropIndex('expenses_branch_date_status_idx'));
        Schema::table('cash_movements', fn (Blueprint $table) => $table->dropIndex('cash_movements_branch_date_type_idx'));
        Schema::table('sales_returns', fn (Blueprint $table) => $table->dropIndex('sales_returns_branch_date_idx'));
        Schema::table('purchase_returns', fn (Blueprint $table) => $table->dropIndex('purchase_returns_branch_date_idx'));
        Schema::table('goods_receipts', fn (Blueprint $table) => $table->dropIndex('receipts_branch_date_status_idx'));
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_branch_active_name_idx');
            $table->dropIndex('products_branch_active_sku_idx');
        });
    }
};
