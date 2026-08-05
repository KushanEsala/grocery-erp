<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 50);
            $table->unsignedTinyInteger('decimal_places')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->decimal('rate', 7, 4)->default(0);
            $table->boolean('inclusive')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10);
            $table->foreignId('store_id')->constrained('stores');
            $table->string('code', 30);
            $table->string('name', 80);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['branch_code', 'code']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10);
            $table->string('sku', 50);
            $table->string('name', 150);
            $table->string('local_name', 150)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('m_brands')->nullOnDelete();
            $table->foreignId('base_unit_id')->constrained('units');
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->foreignId('preferred_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->decimal('latest_cost', 15, 4)->default(0);
            $table->decimal('average_cost', 15, 4)->default(0);
            $table->decimal('retail_price', 15, 4)->default(0);
            $table->decimal('wholesale_price', 15, 4)->nullable();
            $table->decimal('reorder_level', 15, 4)->default(0);
            $table->decimal('minimum_stock', 15, 4)->default(0);
            $table->decimal('maximum_stock', 15, 4)->nullable();
            $table->decimal('minimum_order_qty', 15, 4)->default(1);
            $table->boolean('batch_tracked')->default(false);
            $table->boolean('expiry_tracked')->default(false);
            $table->boolean('weighted')->default(false);
            $table->boolean('allow_decimal_qty')->default(false);
            $table->boolean('active')->default(true);
            $table->string('shelf_location', 80)->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->unique(['branch_code', 'sku']);
            $table->index(['branch_code', 'name']);
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 80)->unique();
            $table->boolean('primary')->default(false);
            $table->timestamps();
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('conversion_factor', 15, 6)->default(1);
            $table->decimal('selling_price', 15, 4)->nullable();
            $table->decimal('purchase_cost', 15, 4)->nullable();
            $table->boolean('can_sell')->default(true);
            $table->boolean('can_purchase')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
        });

        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10);
            $table->foreignId('store_id')->constrained('stores');
            $table->foreignId('product_id')->constrained();
            $table->string('batch_no', 80);
            $table->date('manufactured_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('selling_price', 15, 4)->nullable();
            $table->timestamps();
            $table->unique(['branch_code', 'store_id', 'product_id', 'batch_no'], 'batch_scope_unique');
            $table->index(['branch_code', 'expiry_date']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10);
            $table->foreignId('store_id')->constrained('stores');
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->string('transaction_type', 40);
            $table->string('reference_no', 80);
            $table->decimal('quantity_in', 18, 6)->default(0);
            $table->decimal('quantity_out', 18, 6)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_code', 'store_id', 'product_id']);
            $table->index(['transaction_type', 'reference_no']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10)->nullable();
            $table->string('name', 120);
            $table->enum('type', ['percentage', 'fixed', 'price', 'buy_x_get_y', 'quantity_break']);
            $table->enum('target_type', ['product', 'category', 'brand', 'basket']);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->decimal('value', 15, 4)->default(0);
            $table->decimal('minimum_qty', 15, 4)->nullable();
            $table->decimal('minimum_subtotal', 15, 4)->nullable();
            $table->decimal('buy_qty', 15, 4)->nullable();
            $table->decimal('get_qty', 15, 4)->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('stackable')->default(false);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['branch_code', 'active', 'starts_at', 'ends_at']);
        });

        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('register_id')->constrained('registers');
            $table->foreignId('cashier_id')->constrained('users');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('opening_float', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('variance', 15, 2)->nullable();
            $table->enum('status', ['open', 'closed', 'reopened'])->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_code', 'status']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('store_id')->constrained('stores');
            $table->foreignId('register_id')->nullable()->constrained('registers')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('cashier_shifts')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->dateTime('sold_at');
            $table->enum('status', ['held', 'completed', 'voided', 'partially_returned', 'returned'])->default('completed');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamps();
            $table->index(['branch_code', 'sold_at', 'status']);
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->foreignId('unit_id')->constrained('units');
            $table->string('sku', 50);
            $table->string('description', 150);
            $table->string('unit_code', 20);
            $table->decimal('conversion_factor', 15, 6)->default(1);
            $table->decimal('quantity', 18, 6);
            $table->decimal('base_quantity', 18, 6);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('gross_total', 15, 2);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->foreignId('promotion_id')->nullable()->constrained('promotions')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->enum('method', ['cash', 'card', 'bank_transfer', 'mobile', 'store_credit', 'credit']);
            $table->decimal('amount', 15, 2);
            $table->string('reference', 100)->nullable();
            $table->decimal('tendered', 15, 2)->nullable();
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('sale_id')->constrained('sales');
            $table->foreignId('store_id')->constrained('stores');
            $table->decimal('refund_total', 15, 2);
            $table->enum('refund_method', ['cash', 'card', 'bank_transfer', 'mobile', 'store_credit', 'exchange']);
            $table->string('reason', 255);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('sale_line_id')->constrained('sale_lines');
            $table->decimal('quantity', 18, 6);
            $table->decimal('base_quantity', 18, 6);
            $table->decimal('refund_amount', 15, 2);
            $table->enum('condition', ['saleable', 'damaged', 'expired'])->default('saleable');
            $table->timestamps();
        });

        $this->createPurchasingTables();
        $this->createStockControlTables();
        $this->createCashAndAdminTables();
    }

    private function createPurchasingTables(): void
    {
        Schema::create('grocery_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('store_id')->constrained('stores');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->enum('status', ['draft', 'approved', 'partially_received', 'received', 'cancelled', 'closed'])->default('draft');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['branch_code', 'status', 'order_date']);
        });

        Schema::create('grocery_purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('grocery_purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('unit_id')->constrained('units');
            $table->decimal('conversion_factor', 15, 6)->default(1);
            $table->decimal('ordered_quantity', 18, 6);
            $table->decimal('free_quantity', 18, 6)->default(0);
            $table->decimal('received_quantity', 18, 6)->default(0);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('purchase_order_id')->nullable()->constrained('grocery_purchase_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('store_id')->constrained('stores');
            $table->string('supplier_invoice_no', 100);
            $table->date('supplier_invoice_date');
            $table->dateTime('received_at');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('posted');
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->boolean('credit_purchase')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['branch_code', 'supplier_id', 'supplier_invoice_no'], 'supplier_invoice_unique');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('grocery_purchase_order_lines')->nullOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('unit_id')->constrained('units');
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('conversion_factor', 15, 6)->default(1);
            $table->decimal('quantity', 18, 6);
            $table->decimal('free_quantity', 18, 6)->default(0);
            $table->decimal('accepted_quantity', 18, 6);
            $table->decimal('rejected_quantity', 18, 6)->default(0);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('selling_price', 15, 4)->nullable();
            $table->decimal('line_total', 15, 2);
            $table->string('batch_no', 80)->nullable();
            $table->date('manufactured_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('store_id')->constrained('stores');
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('posted');
            $table->decimal('total', 15, 2);
            $table->string('reason', 255);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->nullOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();
        });
    }

    private function createStockControlTables(): void
    {
        Schema::create('grocery_stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('from_store_id')->constrained('stores');
            $table->foreignId('to_store_id')->constrained('stores');
            $table->enum('status', ['requested', 'dispatched', 'in_transit', 'received', 'cancelled'])->default('requested');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('grocery_stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('grocery_stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('received_quantity', 18, 6)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('store_id')->constrained('stores');
            $table->enum('reason', ['damage', 'spoilage', 'expiry', 'theft', 'correction', 'opening']);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('quantity_delta', 18, 6);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('store_id')->constrained('stores');
            $table->enum('type', ['full', 'cycle'])->default('cycle');
            $table->enum('status', ['draft', 'counting', 'review', 'posted', 'cancelled'])->default('draft');
            $table->dateTime('snapshot_at');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained('stock_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->nullOnDelete();
            $table->decimal('system_quantity', 18, 6);
            $table->decimal('counted_quantity', 18, 6)->nullable();
            $table->decimal('variance', 18, 6)->nullable();
            $table->timestamps();
        });
    }

    private function createCashAndAdminTables(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10);
            $table->foreignId('shift_id')->nullable()->constrained('cashier_shifts')->nullOnDelete();
            $table->enum('type', ['cash_in', 'cash_out', 'cash_drop']);
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255);
            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10)->nullable();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('grocery_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('category_id')->constrained('expense_categories');
            $table->date('expense_date');
            $table->string('payee', 120)->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['cash', 'card', 'bank_transfer', 'mobile']);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('posted');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_account_entries', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10);
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->string('reference_type', 40);
            $table->string('reference_no', 80);
            $table->date('entry_date');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('grocery_supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 80)->unique();
            $table->string('branch_code', 10);
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->enum('method', ['cash', 'card', 'bank_transfer', 'cheque']);
            $table->string('reference', 100)->nullable();
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('entity_type', 80);
            $table->string('entity_id', 80)->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['branch_code', 'action', 'created_at']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10)->nullable();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->timestamps();
            $table->unique(['branch_code', 'key']);
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10);
            $table->string('document_type', 40);
            $table->string('prefix', 20);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['branch_code', 'document_type']);
        });
    }

    public function down(): void
    {
        $tables = [
            'document_sequences', 'app_settings', 'audit_logs', 'grocery_supplier_payments',
            'supplier_account_entries', 'grocery_expenses', 'expense_categories', 'cash_movements',
            'stock_count_lines', 'stock_counts', 'stock_adjustment_lines', 'stock_adjustments',
            'grocery_stock_transfer_lines', 'grocery_stock_transfers', 'purchase_return_lines',
            'purchase_returns', 'goods_receipt_lines', 'goods_receipts', 'grocery_purchase_order_lines',
            'grocery_purchase_orders', 'sales_return_lines', 'sales_returns', 'sale_payments',
            'sale_lines', 'sales', 'cashier_shifts', 'promotions', 'inventory_movements',
            'product_batches', 'product_units', 'product_barcodes', 'products', 'registers',
            'tax_rates', 'units',
        ];

        Schema::disableForeignKeyConstraints();
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
