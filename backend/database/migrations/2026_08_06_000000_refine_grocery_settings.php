<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('suppliers', 'type')) {
            Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('type'));
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->string('secondary_language', 20)->nullable()->after('timezone');
            $table->text('receipt_secondary_footer')->nullable()->after('receipt_footer');
            $table->boolean('customer_credit_enabled')->default(false);
            $table->boolean('post_dated_cheques_enabled')->default(false);
            $table->boolean('accounting_enabled')->default(false);
            $table->boolean('bilingual_receipts_enabled')->default(false);
            $table->string('scale_barcode_prefix', 8)->nullable();
            $table->unsignedTinyInteger('scale_product_digits')->default(5);
            $table->unsignedTinyInteger('scale_weight_digits')->default(5);
            $table->boolean('cash_drawer_enabled')->default(false);
            $table->string('cash_drawer_command', 120)->nullable();
            $table->boolean('label_printer_enabled')->default(false);
            $table->string('label_printer_name', 120)->nullable();
            $table->string('receipt_printer_name', 120)->nullable();
        });

        Schema::create('chart_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10);
            $table->string('code', 30);
            $table->string('name', 120);
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->foreignId('parent_id')->nullable()->constrained('chart_accounts')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['branch_code', 'code']);
        });

        Schema::create('payment_cheques', function (Blueprint $table) {
            $table->id();
            $table->string('branch_code', 10);
            $table->string('direction', 10)->default('outgoing');
            $table->string('reference_type', 40);
            $table->unsignedBigInteger('reference_id');
            $table->string('cheque_no', 80);
            $table->string('bank_name', 120);
            $table->date('cheque_date');
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'cleared', 'returned', 'cancelled'])->default('pending');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_code', 'cheque_no', 'direction']);
        });

        Schema::create('customer_account_entries', function (Blueprint $table) {
            $table->id(); $table->string('branch_code', 10); $table->foreignId('customer_id')->constrained('customers');
            $table->string('reference_type', 40); $table->string('reference_no', 80); $table->date('entry_date');
            $table->decimal('debit', 15, 2)->default(0); $table->decimal('credit', 15, 2)->default(0);
            $table->foreignId('created_by')->constrained('users'); $table->timestamps();
        });

        $companyId = DB::table('companies')->orderBy('id')->value('id');
        if ($companyId) DB::table('branch_dels')->whereNull('company_id')->update(['company_id' => $companyId]);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_entries');
        Schema::dropIfExists('payment_cheques');
        Schema::dropIfExists('chart_accounts');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'secondary_language', 'receipt_secondary_footer', 'customer_credit_enabled',
                'post_dated_cheques_enabled', 'accounting_enabled', 'bilingual_receipts_enabled',
                'scale_barcode_prefix', 'scale_product_digits', 'scale_weight_digits',
                'cash_drawer_enabled', 'cash_drawer_command', 'label_printer_enabled',
                'label_printer_name', 'receipt_printer_name',
            ]);
        });
        Schema::table('suppliers', fn (Blueprint $table) => $table->string('type', 20)->default('normal'));
    }
};
