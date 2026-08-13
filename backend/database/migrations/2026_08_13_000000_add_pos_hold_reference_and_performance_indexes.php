<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('hold_reference', 20)->nullable()->after('status');
            $table->index(['branch_code', 'status', 'hold_reference'], 'sales_branch_status_hold_ref_idx');
            $table->index(['branch_code', 'customer_id', 'sold_at'], 'sales_branch_customer_date_idx');
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->index(['product_id', 'sale_id'], 'sale_lines_product_sale_idx');
            $table->index(['sale_id', 'product_batch_id'], 'sale_lines_sale_batch_idx');
        });

        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->index(['product_id', 'barcode'], 'product_barcodes_product_barcode_idx');
        });

        Schema::table('product_batches', function (Blueprint $table) {
            $table->index(['branch_code', 'store_id', 'product_id', 'quantity', 'expiry_date'], 'batches_fefo_idx');
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->index(['branch_code', 'store_id', 'product_id', 'created_at'], 'inventory_lookup_idx');
            $table->index(['product_batch_id', 'created_at'], 'inventory_batch_date_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(['BC', 'active', 'name'], 'customers_branch_active_name_idx');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->index(['BC', 'active', 'name'], 'suppliers_branch_active_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropIndex('suppliers_branch_active_name_idx'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropIndex('customers_branch_active_name_idx'));
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('inventory_lookup_idx');
            $table->dropIndex('inventory_batch_date_idx');
        });
        Schema::table('product_batches', fn (Blueprint $table) => $table->dropIndex('batches_fefo_idx'));
        Schema::table('product_barcodes', fn (Blueprint $table) => $table->dropIndex('product_barcodes_product_barcode_idx'));
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropIndex('sale_lines_product_sale_idx');
            $table->dropIndex('sale_lines_sale_batch_idx');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_branch_status_hold_ref_idx');
            $table->dropIndex('sales_branch_customer_date_idx');
            $table->dropColumn('hold_reference');
        });
    }
};
