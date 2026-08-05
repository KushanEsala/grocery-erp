<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id(); $table->string('name', 100); $table->text('address')->nullable();
            $table->string('phone', 30)->nullable(); $table->string('email', 100)->nullable();
            $table->string('tax_number', 80)->nullable(); $table->string('currency', 3)->default('LKR');
            $table->string('timezone', 60)->default('Asia/Colombo'); $table->text('receipt_footer')->nullable(); $table->timestamps();
        });

        Schema::create('branch_dels', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('bccode', 10)->unique(); $table->string('name', 100); $table->string('phone', 30)->nullable();
            $table->text('address')->nullable(); $table->enum('status', ['active', 'inactive'])->default('active'); $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('name', 50)->unique(); $table->text('description')->nullable(); $table->timestamps();
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id(); $table->foreignId('role_id')->constrained()->cascadeOnDelete(); $table->string('module', 50);
            $table->boolean('can_create')->default(false); $table->boolean('can_read')->default(false);
            $table->boolean('can_update')->default(false); $table->boolean('can_delete')->default(false);
            $table->string('BC', 10); $table->string('UID', 50); $table->timestamps(); $table->unique(['role_id', 'module']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id(); $table->string('name', 80); $table->string('location', 150)->nullable();
            $table->string('BC', 10); $table->string('UID', 50); $table->boolean('active')->default(true); $table->timestamps();
            $table->unique(['BC', 'name']);
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id(); $table->string('name', 80); $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('BC', 10); $table->string('UID', 50); $table->timestamps(); $table->unique(['BC', 'name']);
        });
        Schema::create('m_brands', function (Blueprint $table) {
            $table->id(); $table->string('name', 80); $table->string('BC', 10); $table->string('UID', 50); $table->timestamps();
            $table->unique(['BC', 'name']);
        });
        Schema::create('customers', function (Blueprint $table) {
            $table->id(); $table->string('Code', 50); $table->string('name', 120); $table->string('NIC', 30)->nullable();
            $table->string('phone', 30)->nullable(); $table->string('email', 100)->nullable(); $table->text('address')->nullable();
            $table->string('tax_number', 80)->nullable(); $table->string('loyalty_number', 80)->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0); $table->decimal('advance_balance', 15, 2)->default(0);
            $table->boolean('active')->default(true); $table->string('BC', 10); $table->string('UID', 50); $table->timestamps();
            $table->unique(['BC', 'Code']); $table->unique(['BC', 'NIC']);
        });
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id(); $table->string('Code', 50); $table->string('name', 120); $table->string('contact_person', 100)->nullable();
            $table->string('phone', 30)->nullable(); $table->string('email', 100)->nullable(); $table->text('address')->nullable();
            $table->string('tax_number', 80)->nullable(); $table->decimal('credit_limit', 15, 2)->default(0);
            $table->unsignedInteger('payment_terms_days')->default(0); $table->boolean('active')->default(true);
            $table->string('type', 20)->default('normal'); $table->string('BC', 10); $table->string('UID', 50); $table->timestamps();
            $table->unique(['BC', 'Code']);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['role_id']));
        Schema::dropIfExists('suppliers'); Schema::dropIfExists('customers'); Schema::dropIfExists('m_brands');
        Schema::dropIfExists('categories'); Schema::dropIfExists('stores'); Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles'); Schema::dropIfExists('branch_dels'); Schema::dropIfExists('companies');
    }
};
