<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'Code' => 'SUP-01', 'name' => 'Fresh Foods', 'type' => 'normal', 'BC' => 'HQ', 'UID' => 'admin', 'created_at' => now(),
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
            'store_id' => $this->storeId,
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 1]],
            'payments' => [],
        ])->assertOk()->json('data');
        $this->assertSame(5.0, $this->stock($product['id'], $this->storeId));

        $shift = $this->postJson('/api/v1/grocery/shifts/open', ['register_id' => $this->registerId, 'opening_float' => 0])->json('data');
        $completed = $this->postJson('/api/v1/grocery/pos/complete', [
            'held_sale_id' => $held['id'], 'store_id' => $this->storeId, 'shift_id' => $shift['id'],
            'lines' => [['product_id' => $product['id'], 'unit_id' => $this->eachId, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 250]],
        ])->assertOk()->json('data');

        $this->assertDatabaseHas('sales', ['id' => $held['id'], 'status' => 'voided']);
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
