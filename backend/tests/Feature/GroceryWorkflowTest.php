<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class GroceryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $storeId;
    private int $registerId;
    private int $eachId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@erp.com')->firstOrFail();
        Sanctum::actingAs($this->admin);
        $this->storeId = (int) DB::table('stores')->where('BC', 'HQ')->value('id');
        $this->registerId = (int) DB::table('registers')->where('branch_code', 'HQ')->value('id');
        $this->eachId = (int) DB::table('units')->where('code', 'EA')->value('id');
    }

    public function test_product_opening_stock_shift_sale_and_return_are_atomic(): void
    {
        $product = $this->createProduct('RICE-001', 'Rice Packet', '890100000001');

        $this->postJson('/api/v1/grocery/stock-adjustments', [
            'store_id' => $this->storeId, 'reason' => 'opening',
            'lines' => [['product_id' => $product['id'], 'quantity_delta' => 10]],
        ])->assertOk();

        $shift = $this->postJson('/api/v1/grocery/shifts/open', [
            'register_id' => $this->registerId, 'opening_float' => 1000,
        ])->assertOk()->json('data');

        $sale = $this->postJson('/api/v1/grocery/pos/complete', [
            'store_id' => $this->storeId, 'register_id' => $this->registerId, 'shift_id' => $shift['id'],
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 2]],
            'payments' => [
                ['method' => 'cash', 'amount' => 300, 'tendered' => 300],
                ['method' => 'card', 'amount' => 200, 'reference' => 'CARD-001'],
            ],
        ])->assertOk()->json('data');

        $this->assertSame(8.0, (float) $this->getJson('/api/v1/grocery/inventory')->assertOk()->json('data.0.quantity'));
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'completed', 'grand_total' => 500]);
        $this->assertDatabaseCount('sale_payments', 2);
        $this->postJson("/api/v1/grocery/sales/{$sale['id']}/print", [])->assertOk()->assertJsonPath('data.print_count', 1);
        $this->postJson("/api/v1/grocery/sales/{$sale['id']}/print", ['reason' => 'Customer requested a copy'])->assertOk()->assertJsonPath('data.print_count', 2);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'sale', 'entity_id' => $sale['id'], 'action' => 'reprint']);

        $this->postJson('/api/v1/grocery/sales-returns', [
            'sale_id' => $sale['id'], 'store_id' => $this->storeId, 'reason' => 'Customer changed mind',
            'refund_method' => 'cash', 'lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'quantity' => 1, 'condition' => 'saleable']],
        ])->assertOk();

        $this->assertSame(9.0, (float) $this->getJson('/api/v1/grocery/inventory')->json('data.0.quantity'));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'sale', 'action' => 'return']);
    }

    public function test_goods_receipt_tracks_batch_expiry_and_fefo_sale(): void
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'Code' => 'SUP-01', 'name' => 'Fresh Foods', 'BC' => 'HQ', 'UID' => 'admin', 'created_at' => now(),
        ]);
        $product = $this->createProduct('MILK-001', 'Fresh Milk', '890100000002', true);

        foreach ([['B-LATE', now()->addDays(30)->toDateString()], ['B-EARLY', now()->addDays(10)->toDateString()]] as [$batch, $expiry]) {
            $this->postJson('/api/v1/grocery/goods-receipts', [
                'supplier_id' => $supplierId, 'store_id' => $this->storeId, 'supplier_invoice_no' => 'INV-'.$batch,
                'supplier_invoice_date' => today()->toDateString(), 'credit_purchase' => true,
                'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 5, 'unit_cost' => 100, 'selling_price' => 150, 'batch_no' => $batch, 'expiry_date' => $expiry]],
            ])->assertOk();
        }

        $shift = $this->postJson('/api/v1/grocery/shifts/open', ['register_id' => $this->registerId, 'opening_float' => 0])->json('data');
        $sale = $this->postJson('/api/v1/grocery/pos/complete', [
            'store_id' => $this->storeId, 'shift_id' => $shift['id'],
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 3]],
            'payments' => [['method' => 'cash', 'amount' => 450]],
        ])->assertOk()->json('data');

        $earlyBatch = DB::table('product_batches')->where('batch_no', 'B-EARLY')->first();
        $this->assertSame($earlyBatch->id, $sale['lines'][0]['product_batch_id']);
        $this->assertSame(2.0, (float) DB::table('product_batches')->where('id', $earlyBatch->id)->value('quantity'));

        $this->postJson('/api/v1/grocery/goods-receipts', [
            'supplier_id' => $supplierId, 'store_id' => $this->storeId, 'supplier_invoice_no' => 'INV-EXPIRED',
            'supplier_invoice_date' => today()->toDateString(),
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 1, 'unit_cost' => 80, 'batch_no' => 'OLD', 'expiry_date' => now()->subDay()->toDateString()]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.expiry_date');

        $expiryOnly = $this->postJson('/api/v1/grocery/products', [
            'sku' => 'YOGURT-001', 'name' => 'Yogurt Cup', 'base_unit_id' => $this->eachId,
            'retail_price' => 180, 'latest_cost' => 120, 'batch_tracked' => false,
            'expiry_tracked' => true, 'barcodes' => ['890100000099'],
        ])->assertOk()->json('data');
        $expiryDate = now()->addDays(14)->toDateString();
        $this->postJson('/api/v1/grocery/goods-receipts', [
            'supplier_id' => $supplierId, 'store_id' => $this->storeId,
            'supplier_invoice_no' => 'INV-EXPIRY-ONLY', 'supplier_invoice_date' => today()->toDateString(),
            'lines' => [[
                'product_id' => $expiryOnly['id'], 'unit_id' => $this->eachId, 'quantity' => 2,
                'unit_cost' => 120, 'selling_price' => 180, 'expiry_date' => $expiryDate,
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('product_batches', [
            'product_id' => $expiryOnly['id'], 'expiry_date' => $expiryDate, 'quantity' => 2,
        ]);
    }

    public function test_tracked_opening_stock_is_sellable_and_matches_pos_catalog(): void
    {
        $product = $this->postJson('/api/v1/grocery/products', [
            'sku' => 'JUICE-OPEN', 'name' => 'Opening Juice', 'base_unit_id' => $this->eachId,
            'retail_price' => 200, 'latest_cost' => 120, 'batch_tracked' => false,
            'expiry_tracked' => true, 'barcodes' => ['890100000088'],
        ])->assertOk()->json('data');

        $this->postJson('/api/v1/grocery/stock-adjustments', [
            'store_id' => $this->storeId, 'reason' => 'opening',
            'lines' => [['product_id' => $product['id'], 'quantity_delta' => 12]],
        ])->assertOk();

        $this->assertDatabaseHas('product_batches', [
            'store_id' => $this->storeId, 'product_id' => $product['id'],
            'batch_no' => 'SYSTEM-OPENING', 'quantity' => 12,
        ]);
        $catalog = collect($this->getJson("/api/v1/grocery/lookups/products?store_id={$this->storeId}&search=JUICE-OPEN")
            ->assertOk()->json('data'));
        $this->assertSame(12.0, (float) $catalog->firstWhere('id', $product['id'])['stock']);

        $shift = $this->postJson('/api/v1/grocery/shifts/open', [
            'register_id' => $this->registerId, 'opening_float' => 0,
        ])->assertOk()->json('data');
        $this->postJson('/api/v1/grocery/pos/complete', [
            'store_id' => $this->storeId, 'shift_id' => $shift['id'],
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => 400]],
        ])->assertOk();

        $this->assertSame(10.0, (float) DB::table('product_batches')
            ->where('product_id', $product['id'])->where('batch_no', 'SYSTEM-OPENING')->value('quantity'));
    }

    public function test_pos_rejects_a_store_that_does_not_match_the_open_register(): void
    {
        $product = $this->createProduct('STORE-001', 'Store Guard Product', '890100000077');
        $otherStore = (int) DB::table('stores')->where('BC', 'HQ')->where('id', '!=', $this->storeId)->value('id');
        $this->postJson('/api/v1/grocery/stock-adjustments', [
            'store_id' => $otherStore, 'reason' => 'opening',
            'lines' => [['product_id' => $product['id'], 'quantity_delta' => 5]],
        ])->assertOk();
        $shift = $this->postJson('/api/v1/grocery/shifts/open', [
            'register_id' => $this->registerId, 'opening_float' => 0,
        ])->assertOk()->json('data');

        $this->postJson('/api/v1/grocery/pos/complete', [
            'store_id' => $otherStore, 'shift_id' => $shift['id'],
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 250]],
        ])->assertUnprocessable()->assertJsonValidationErrors('store_id');
    }

    public function test_unit_conversion_transfer_and_stock_count_reconcile_base_quantity(): void
    {
        $packId = (int) DB::table('units')->where('code', 'PK')->value('id');
        $product = $this->createProduct('SOAP-001', 'Soap', '890100000003', false, [
            ['unit_id' => $packId, 'conversion_factor' => 6, 'selling_price' => 600],
        ]);
        $destinationId = DB::table('stores')->where('BC', 'HQ')->where('id', '!=', $this->storeId)->value('id');

        $this->postJson('/api/v1/grocery/stock-adjustments', ['store_id' => $this->storeId, 'reason' => 'opening', 'lines' => [['product_id' => $product['id'], 'quantity_delta' => 24]]])->assertOk();
        $transfer = $this->postJson('/api/v1/grocery/transfers', ['from_store_id' => $this->storeId, 'to_store_id' => $destinationId, 'lines' => [['product_id' => $product['id'], 'quantity' => 6]]])->assertOk()->json('data');
        $this->postJson("/api/v1/grocery/transfers/{$transfer['id']}/receive")->assertOk();

        $this->assertSame(18.0, $this->stock($product['id'], $this->storeId));
        $this->assertSame(6.0, $this->stock($product['id'], $destinationId));

        $shift = $this->postJson('/api/v1/grocery/shifts/open', ['register_id' => $this->registerId, 'opening_float' => 0])->json('data');
        $this->postJson('/api/v1/grocery/pos/complete', [
            'store_id' => $this->storeId, 'shift_id' => $shift['id'],
            'lines' => [['product_id' => $product['id'], 'unit_id' => $packId, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 600]],
        ])->assertOk();
        $this->assertSame(12.0, $this->stock($product['id'], $this->storeId));

        $count = $this->postJson('/api/v1/grocery/stock-counts', [
            'store_id' => $this->storeId, 'type' => 'cycle', 'product_ids' => [$product['id']],
        ])->assertOk()->json('data');
        $countLine = $this->getJson("/api/v1/grocery/stock-counts/{$count['id']}")->assertOk()->json('data.lines.0');

        // A movement after the snapshot must be considered when posting. The
        // physical count is reconciled to the live balance, not the stale 12.
        $this->postJson('/api/v1/grocery/stock-adjustments', [
            'store_id' => $this->storeId, 'reason' => 'correction',
            'lines' => [['product_id' => $product['id'], 'quantity_delta' => 2]],
        ])->assertOk();
        $this->postJson("/api/v1/grocery/stock-counts/{$count['id']}/post", [
            'reason' => 'Verified physical count', 'lines' => [['line_id' => $countLine['id'], 'counted_quantity' => 11]],
        ])->assertOk();
        $this->assertSame(11.0, $this->stock($product['id'], $this->storeId));
    }

    public function test_held_sale_can_be_resumed_and_completed_sale_can_be_audited_void(): void
    {
        $product = $this->createProduct('TEA-001', 'Tea Packet', '890100000004');
        $this->postJson('/api/v1/grocery/stock-adjustments', [
            'store_id' => $this->storeId, 'reason' => 'opening',
            'lines' => [['product_id' => $product['id'], 'quantity_delta' => 5]],
        ])->assertOk();

        $held = $this->postJson('/api/v1/grocery/pos/hold', [
            'store_id' => $this->storeId, 'hold_reference' => '4821',
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 1]],
            'payments' => [],
        ])->assertOk()->json('data');
        $this->assertSame('4821', $held['hold_reference']);
        $this->assertSame(5.0, $this->stock($product['id'], $this->storeId));

        $pausedAgain = $this->postJson('/api/v1/grocery/pos/hold', [
            'held_sale_id' => $held['id'], 'store_id' => $this->storeId,
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 1]],
            'payments' => [],
        ])->assertOk()->json('data');
        $this->assertDatabaseHas('sales', ['id' => $held['id'], 'status' => 'voided']);
        $this->assertDatabaseHas('sales', ['id' => $pausedAgain['id'], 'status' => 'held']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'sale', 'entity_id' => $held['id'], 'action' => 'rehold']);

        $shift = $this->postJson('/api/v1/grocery/shifts/open', ['register_id' => $this->registerId, 'opening_float' => 0])->json('data');
        $completed = $this->postJson('/api/v1/grocery/pos/complete', [
            'held_sale_id' => $pausedAgain['id'], 'store_id' => $this->storeId, 'shift_id' => $shift['id'],
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 1]],
            'bill_discount_type' => 'percent', 'bill_discount_value' => 10,
            'payments' => [['method' => 'cash', 'amount' => 225]],
        ])->assertOk()->json('data');

        $this->assertDatabaseHas('sales', ['id' => $pausedAgain['id'], 'status' => 'voided']);
        $this->assertSame(225.0, (float) $completed['grand_total']);
        $this->assertSame(25.0, (float) $completed['discount_total']);
        $this->assertSame(4.0, $this->stock($product['id'], $this->storeId));
        $this->postJson("/api/v1/grocery/sales/{$completed['id']}/void", ['reason' => 'Cashier scanned wrong basket'])->assertOk();
        $this->assertSame(5.0, $this->stock($product['id'], $this->storeId));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'sale', 'entity_id' => $completed['id'], 'action' => 'void']);
    }

    public function test_branch_scope_permissions_and_reports_are_enforced(): void
    {
        $this->getJson('/api/v1/grocery/dashboard')->assertOk()->assertJsonStructure(['data' => ['sales', 'gross_profit', 'transactions', 'low_stock_count']]);
        $this->getJson('/api/v1/grocery/reports/inventory')->assertOk();
        $this->getJson('/api/v1/hp')->assertNotFound();
        $this->getJson('/api/v1/service-tickets')->assertNotFound();
    }

    public function test_every_user_can_change_password_only_after_confirming_current_password(): void
    {
        $otherToken = $this->admin->createToken('another-device');

        $this->putJson('/api/v1/user/password', [
            'current_password' => 'not-the-current-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check((string) env('ERP_DEMO_PASSWORD', 'password'), $this->admin->fresh()->password));

        $this->putJson('/api/v1/user/password', [
            'current_password' => (string) env('ERP_DEMO_PASSWORD', 'password'),
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk()->assertJsonPath('message', 'Password changed successfully. Other signed-in devices have been logged out.');

        $this->assertTrue(Hash::check('new-secure-password', $this->admin->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }

    public function test_super_admin_can_edit_and_safely_delete_user_accounts(): void
    {
        $manager = User::where('email', 'manager@erp.com')->firstOrFail();
        $cashier = User::where('email', 'cashier@erp.com')->firstOrFail();
        $managerRoleId = (int) DB::table('roles')->where('name', 'Manager')->value('id');

        $manager->createToken('manager-device');
        $this->putJson("/api/v1/users/{$manager->id}", [
            'username' => 'shop-manager',
            'email' => 'shop.manager@example.com',
            'role_id' => $managerRoleId,
        ])->assertOk()->assertJsonPath('data.username', 'shop-manager');

        $this->deleteJson("/api/v1/users/{$this->admin->id}")
            ->assertUnprocessable()->assertJsonPath('message', 'You cannot delete your own account.');

        $this->putJson("/api/v1/users/{$this->admin->id}", ['role_id' => $managerRoleId])
            ->assertUnprocessable()->assertJsonPath('message', 'You cannot change your own role while signed in.');

        $this->deleteJson("/api/v1/users/{$manager->id}")
            ->assertOk()->assertJsonPath('message', 'User account deleted. Historical transactions were preserved.');

        $this->assertSoftDeleted('users', ['id' => $manager->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $manager->id]);

        $this->deleteJson("/api/v1/users/{$cashier->id}")->assertOk();
        $this->getJson('/api/v1/users')->assertOk()
            ->assertJsonMissing(['email' => 'shop.manager@example.com'])
            ->assertJsonMissing(['email' => 'cashier@erp.com']);
    }

    public function test_purchase_order_rejects_invalid_picker_values_and_preserves_product_prices(): void
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'Code' => 'SUP-PO', 'name' => 'Daily Grocery Supply', 'BC' => 'HQ', 'UID' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = $this->createProduct('FLOUR-001', 'Wheat Flour 1kg', '479100000001');

        $payload = [
            'supplier_id' => $supplierId, 'store_id' => $this->storeId,
            'order_date' => today()->toDateString(),
            'lines' => [['product_id' => 0, 'unit_id' => 0, 'quantity' => 10, 'unit_cost' => 120]],
        ];
        $this->postJson('/api/v1/grocery/purchase-orders', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.product_id', 'lines.0.unit_id']);
        $this->assertDatabaseCount('grocery_purchase_orders', 0);

        $payload['lines'][0] = ['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 10, 'unit_cost' => 120];
        $this->postJson('/api/v1/grocery/purchase-orders', $payload)
            ->assertOk()->assertJsonPath('data.grand_total', '1200.00');

        $this->putJson("/api/v1/grocery/products/{$product['id']}", [
            'latest_cost' => 175.50, 'retail_price' => 289.90, 'base_unit_id' => $this->eachId,
        ])->assertOk();
        $catalogue = collect($this->getJson('/api/v1/grocery/products')->assertOk()->json('data'));
        $saved = $catalogue->firstWhere('id', $product['id']);
        $this->assertSame(175.5, (float) $saved['latest_cost']);
        $this->assertSame(289.9, (float) $saved['retail_price']);
        $this->assertSame(175.5, (float) $saved['units'][0]['purchase_cost']);
        $this->assertSame(289.9, (float) $saved['units'][0]['selling_price']);
    }

    public function test_company_features_tax_numbering_credit_and_cheque_lifecycle_are_configurable(): void
    {
        $this->assertFalse(Schema::hasColumn('suppliers', 'type'));
        $companyId = (int) DB::table('companies')->value('id');
        $this->putJson("/api/v1/companies/{$companyId}", [
            'currency' => 'LKR', 'timezone' => 'Asia/Colombo', 'receipt_footer' => 'Thank you for shopping with us.',
            'customer_credit_enabled' => true, 'post_dated_cheques_enabled' => true, 'accounting_enabled' => true,
            'bilingual_receipts_enabled' => true, 'secondary_language' => 'si', 'receipt_secondary_footer' => 'ස්තුතියි',
            'scale_barcode_prefix' => '20', 'scale_product_digits' => 5, 'scale_weight_digits' => 5,
            'cash_drawer_enabled' => true, 'cash_drawer_command' => 'ESC/POS',
            'label_printer_enabled' => true, 'label_printer_name' => 'Grocery Labels', 'receipt_printer_name' => 'POS Receipt',
        ])->assertOk()->assertJsonPath('data.customer_credit_enabled', true);

        $tax = $this->postJson('/api/v1/grocery/masters/tax-rates', [
            'name' => 'VAT 18', 'rate' => 18, 'inclusive' => true, 'active' => true,
        ])->assertOk()->json('data');
        $this->putJson("/api/v1/grocery/masters/tax-rates/{$tax['id']}", [
            'name' => 'VAT 15', 'rate' => 15, 'inclusive' => true, 'active' => true,
        ])->assertOk()->assertJsonPath('data.rate', '15.0000');

        $sequence = DB::table('document_sequences')->where('branch_code', 'HQ')->where('document_type', 'sale')->first();
        $this->putJson("/api/v1/grocery/masters/sequences/{$sequence->id}", [
            'document_type' => 'sale', 'prefix' => 'POS-', 'next_number' => 300,
        ])->assertOk()->assertJsonPath('data.next_number', 300);

        $supplierId = DB::table('suppliers')->insertGetId([
            'Code' => 'SUP-PDC', 'name' => 'Cheque Supplier', 'BC' => 'HQ', 'UID' => 'admin',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/grocery/supplier-payments', [
            'supplier_id' => $supplierId, 'payment_date' => today()->toDateString(), 'amount' => 5000,
            'method' => 'cheque', 'reference' => 'PDC-TEST', 'cheque_no' => '000123',
            'bank_name' => 'Test Bank', 'cheque_date' => today()->addWeek()->toDateString(),
        ])->assertOk();
        $chequeId = (int) DB::table('payment_cheques')->value('id');
        $this->patchJson("/api/v1/grocery/cheques/{$chequeId}", ['status' => 'cleared', 'reason' => 'Bank confirmed'])
            ->assertOk()->assertJsonPath('data.status', 'cleared');

        $customerId = DB::table('customers')->insertGetId([
            'Code' => 'CUS-CREDIT', 'name' => 'Credit Customer', 'credit_limit' => 1000,
            'BC' => 'HQ', 'UID' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product = $this->createProduct('SUGAR-001', 'White Sugar 1kg', '479100000002');
        $this->postJson('/api/v1/grocery/stock-adjustments', [
            'store_id' => $this->storeId, 'reason' => 'opening',
            'lines' => [['product_id' => $product['id'], 'quantity_delta' => 5]],
        ])->assertOk();
        $shift = $this->postJson('/api/v1/grocery/shifts/open', ['register_id' => $this->registerId, 'opening_float' => 0])->json('data');
        $this->postJson('/api/v1/grocery/pos/complete', [
            'customer_id' => $customerId, 'store_id' => $this->storeId, 'register_id' => $this->registerId, 'shift_id' => $shift['id'],
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 1]],
            'payments' => [['method' => 'credit', 'amount' => 250]],
        ])->assertOk();
        $this->assertDatabaseHas('customer_account_entries', ['customer_id' => $customerId, 'debit' => 250]);
        $this->postJson('/api/v1/grocery/customer-payments', [
            'customer_id' => $customerId, 'payment_date' => today()->toDateString(),
            'amount' => 300, 'method' => 'cash', 'reference' => 'COUNTER-REPAYMENT',
        ])->assertOk()->assertJsonPath('data.applied_to_credit', 250)->assertJsonPath('data.added_store_credit', 50);
        $this->assertDatabaseHas('customer_account_entries', ['customer_id' => $customerId, 'credit' => 250]);
        $this->assertSame(50.0, (float) DB::table('customers')->where('id', $customerId)->value('advance_balance'));
    }

    public function test_backup_contains_grocery_groups_and_no_excluded_modules(): void
    {
        Storage::fake('local');
        $response = $this->postJson('/api/v1/system/backups', ['mode' => 'continue'])->assertCreated();
        $filename = $response->json('data.filename');
        Storage::disk('local')->assertExists('backups/'.$filename);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open(Storage::disk('local')->path('backups/'.$filename)) === true);
        $this->assertNotFalse($zip->locateName('master-data/products.csv'));
        $this->assertNotFalse($zip->locateName('sales/sales.csv'));
        $this->assertFalse($zip->locateName('hire-purchase/t_hire_purchase_sums.csv'));
        $zip->close();

        $originalCompany = DB::table('companies')->value('name');
        DB::table('companies')->update(['name' => 'Temporary changed name']);
        $this->postJson("/api/v1/system/backups/{$filename}/restore", ['confirmation' => 'RESTORE'])
            ->assertOk()->assertJsonPath('data.restored_from', $filename);
        $this->assertSame($originalCompany, DB::table('companies')->value('name'));
    }

    private function createProduct(string $sku, string $name, string $barcode, bool $tracked = false, array $extraUnits = []): array
    {
        return $this->postJson('/api/v1/grocery/products', [
            'sku' => $sku, 'name' => $name, 'base_unit_id' => $this->eachId, 'retail_price' => 250,
            'latest_cost' => 150, 'batch_tracked' => $tracked, 'expiry_tracked' => $tracked,
            'barcodes' => [$barcode], 'units' => $extraUnits,
        ])->assertOk()->json('data');
    }

    private function stock(int $productId, int $storeId): float
    {
        return (float) DB::table('inventory_movements')->where('product_id', $productId)->where('store_id', $storeId)->selectRaw('COALESCE(SUM(quantity_in - quantity_out), 0) q')->value('q');
    }
}
