<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GroceryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroceryController extends Controller
{
    public function __construct(private readonly GroceryService $grocery) {}

    private function ok(mixed $data, string $message = 'Request completed.')
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    public function options(Request $request)
    {
        $branch = $request->user()->BC;
        return $this->ok([
            'stores' => DB::table('stores')->where('BC', $branch)->orderBy('name')->get(),
            'registers' => DB::table('registers')->where('branch_code', $branch)->where('active', true)->orderBy('name')->get(),
            'units' => DB::table('units')->where('active', true)->orderBy('name')->get(),
            'tax_rates' => DB::table('tax_rates')->where('active', true)->orderBy('name')->get(),
            'categories' => DB::table('categories')->where('BC', $branch)->orderBy('name')->get(),
            'brands' => DB::table('m_brands')->where('BC', $branch)->orderBy('name')->get(),
            'suppliers' => DB::table('suppliers')->where('BC', $branch)->orderBy('name')->get(),
            'customers' => DB::table('customers')->where('BC', $branch)->orderBy('name')->get(),
            'expense_categories' => DB::table('expense_categories')->where(fn ($q) => $q->whereNull('branch_code')->orWhere('branch_code', $branch))->where('active', true)->orderBy('name')->get(),
            'open_shift' => DB::table('cashier_shifts')->where('branch_code', $branch)->where('cashier_id', $request->user()->id)->where('status', 'open')->first(),
            'settings' => DB::table('app_settings')->where(fn ($q) => $q->whereNull('branch_code')->orWhere('branch_code', $branch))->pluck('value', 'key'),
        ]);
    }

    public function products(Request $request)
    {
        return $this->ok($this->grocery->catalog($request->user(), $request->query('search'), $request->integer('store_id') ?: null));
    }

    public function storeProduct(Request $request)
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:150'],
            'local_name' => ['nullable', 'string', 'max:150'], 'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'brand_id' => ['nullable', 'integer', 'exists:m_brands,id'],
            'base_unit_id' => ['required', 'integer', 'exists:units,id'], 'tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'preferred_supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'retail_price' => ['required', 'numeric', 'min:0'], 'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'latest_cost' => ['nullable', 'numeric', 'min:0'], 'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'], 'maximum_stock' => ['nullable', 'numeric', 'min:0'],
            'minimum_order_qty' => ['nullable', 'numeric', 'min:0'], 'batch_tracked' => ['boolean'], 'expiry_tracked' => ['boolean'],
            'weighted' => ['boolean'], 'allow_decimal_qty' => ['boolean'], 'active' => ['boolean'], 'shelf_location' => ['nullable', 'string', 'max:80'],
            'barcodes' => ['array'], 'barcodes.*' => ['string', 'max:80', 'distinct', 'unique:product_barcodes,barcode'],
            'units' => ['array'], 'units.*.unit_id' => ['required', 'integer', 'exists:units,id'],
            'units.*.conversion_factor' => ['required', 'numeric', 'gt:0'], 'units.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'units.*.purchase_cost' => ['nullable', 'numeric', 'min:0'], 'units.*.can_sell' => ['boolean'], 'units.*.can_purchase' => ['boolean'],
        ]);
        $user = $request->user();
        $id = DB::transaction(function () use ($data, $user) {
            if (DB::table('products')->where('branch_code', $user->BC)->where('sku', $data['sku'])->exists()) {
                throw ValidationException::withMessages(['sku' => 'SKU is already used in this branch.']);
            }
            $id = DB::table('products')->insertGetId([
                ...collect($data)->except(['barcodes', 'units'])->all(), 'branch_code' => $user->BC,
                'average_cost' => $data['latest_cost'] ?? 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $barcodes = array_values($data['barcodes'] ?? []);
            foreach ($barcodes as $index => $barcode) DB::table('product_barcodes')->insert([
                'product_id' => $id, 'barcode' => trim($barcode), 'primary' => $index === 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $units = $data['units'] ?? [];
            if (! collect($units)->contains(fn ($unit) => (int) $unit['unit_id'] === (int) $data['base_unit_id'])) {
                $units[] = ['unit_id' => $data['base_unit_id'], 'conversion_factor' => 1, 'selling_price' => $data['retail_price'], 'purchase_cost' => $data['latest_cost'] ?? 0, 'can_sell' => true, 'can_purchase' => true];
            }
            foreach ($units as $unit) DB::table('product_units')->insert([
                'product_id' => $id, 'unit_id' => $unit['unit_id'], 'conversion_factor' => $unit['conversion_factor'],
                'selling_price' => $unit['selling_price'] ?? null, 'purchase_cost' => $unit['purchase_cost'] ?? null,
                'can_sell' => $unit['can_sell'] ?? true, 'can_purchase' => $unit['can_purchase'] ?? true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->grocery->audit($user, 'create', 'product', $id, null, null, $data);
            return $id;
        });
        return $this->ok(collect($this->grocery->catalog($user))->firstWhere('id', $id), 'Product created.');
    }

    public function updateProduct(Request $request, int $id)
    {
        $product = DB::table('products')->where('id', $id)->where('branch_code', $request->user()->BC)->first();
        if (! $product) abort(404);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'], 'local_name' => ['nullable', 'string', 'max:150'],
            'retail_price' => ['sometimes', 'numeric', 'min:0'], 'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['sometimes', 'numeric', 'min:0'], 'active' => ['sometimes', 'boolean'],
            'shelf_location' => ['nullable', 'string', 'max:80'],
        ]);
        DB::table('products')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        $this->grocery->audit($request->user(), 'update', 'product', $id, $request->input('reason'), $product, $data);
        return $this->ok(DB::table('products')->find($id), 'Product updated.');
    }

    public function masterIndex(Request $request, string $resource)
    {
        [$table, $branchColumn] = $this->masterResource($resource);
        $query = DB::table($table);
        if ($branchColumn) $query->where(fn ($q) => $q->whereNull($branchColumn)->orWhere($branchColumn, $request->user()->BC));
        if ($search = $request->query('search')) $query->where('name', 'like', "%{$search}%");
        return $this->ok($query->orderBy('id', 'desc')->paginate(min(100, max(10, $request->integer('per_page', 25)))));
    }

    public function masterStore(Request $request, string $resource)
    {
        [$table, $branchColumn] = $this->masterResource($resource);
        $rules = match ($resource) {
            'units' => ['code' => ['required', 'string', 'max:20', 'unique:units,code'], 'name' => ['required', 'string', 'max:50'], 'decimal_places' => ['integer', 'between:0,6'], 'active' => ['boolean']],
            'tax-rates' => ['name' => ['required', 'string', 'max:50'], 'rate' => ['required', 'numeric', 'between:0,100'], 'inclusive' => ['boolean'], 'active' => ['boolean']],
            'registers' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:80'], 'store_id' => ['required', 'integer', 'exists:stores,id'], 'active' => ['boolean']],
            'promotions' => ['name' => ['required', 'string', 'max:120'], 'type' => ['required', Rule::in(['percentage','fixed','price','buy_x_get_y','quantity_break'])], 'target_type' => ['required', Rule::in(['product','category','brand','basket'])], 'target_id' => ['nullable', 'integer'], 'value' => ['required', 'numeric', 'min:0'], 'minimum_qty' => ['nullable', 'numeric', 'min:0'], 'minimum_subtotal' => ['nullable', 'numeric', 'min:0'], 'buy_qty' => ['nullable', 'numeric', 'min:0'], 'get_qty' => ['nullable', 'numeric', 'min:0'], 'priority' => ['integer', 'min:0'], 'stackable' => ['boolean'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'active' => ['boolean']],
            'expense-categories' => ['name' => ['required', 'string', 'max:100'], 'active' => ['boolean']],
            default => [],
        };
        $data = $request->validate($rules);
        if ($resource === 'registers' && ! DB::table('stores')->where('id', $data['store_id'])->where('BC', $request->user()->BC)->exists()) throw ValidationException::withMessages(['store_id' => 'Store is outside your branch.']);
        if ($branchColumn) $data[$branchColumn] = $request->user()->BC;
        $id = DB::table($table)->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        $this->grocery->audit($request->user(), 'create', $resource, $id, null, null, $data);
        return $this->ok(DB::table($table)->find($id), 'Saved.');
    }

    private function masterResource(string $resource): array
    {
        return match ($resource) {
            'units' => ['units', null], 'tax-rates' => ['tax_rates', null],
            'registers' => ['registers', 'branch_code'], 'promotions' => ['promotions', 'branch_code'],
            'expense-categories' => ['expense_categories', 'branch_code'],
            default => abort(404),
        };
    }

    public function shifts(Request $request)
    {
        return $this->ok(DB::table('cashier_shifts as s')->join('registers as r', 'r.id', '=', 's.register_id')->join('users as u', 'u.id', '=', 's.cashier_id')
            ->where('s.branch_code', $request->user()->BC)->select('s.*', 'r.name as register_name', 'u.username as cashier_name')->orderByDesc('s.opened_at')->paginate(50));
    }

    public function openShift(Request $request)
    {
        $data = $request->validate(['register_id' => ['required', 'integer'], 'opening_float' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:500']]);
        return $this->ok($this->grocery->openShift($request->user(), $data), 'Shift opened.');
    }

    public function closeShift(Request $request, int $id)
    {
        $data = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:500'], 'reason' => ['nullable', 'string', 'max:255']]);
        return $this->ok($this->grocery->closeShift($request->user(), $id, $data), 'Shift closed.');
    }

    public function sales(Request $request)
    {
        $query = DB::table('sales')->where('branch_code', $request->user()->BC)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('from'), fn ($q, $v) => $q->whereDate('sold_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->whereDate('sold_at', '<=', $v));
        return $this->ok($query->orderByDesc('sold_at')->paginate(50));
    }

    public function sale(Request $request, int $id) { return $this->ok($this->grocery->saleDetail($request->user(), $id)); }

    public function printSale(Request $request, int $id)
    {
        $sale = DB::table('sales')->where('id', $id)->where('branch_code', $request->user()->BC)->first();
        if (! $sale) abort(404);
        DB::table('sales')->where('id', $id)->increment('print_count', 1, ['updated_at' => now()]);
        $printed = DB::table('sales')->find($id);
        $this->grocery->audit($request->user(), $printed->print_count > 1 ? 'reprint' : 'print', 'sale', $id, $request->input('reason'));
        return $this->ok($this->grocery->saleDetail($request->user(), $id), $printed->print_count > 1 ? 'Receipt reprint recorded.' : 'Receipt print recorded.');
    }

    public function voidSale(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        return $this->ok($this->grocery->voidSale($request->user(), $id, $data['reason']), 'Sale voided and stock restored.');
    }

    public function completeSale(Request $request)
    {
        $data = $this->validateSale($request, false);
        return $this->ok($this->grocery->completeSale($request->user(), $data), 'Sale completed.');
    }

    public function holdSale(Request $request)
    {
        $data = $this->validateSale($request, true);
        return $this->ok($this->grocery->completeSale($request->user(), $data, true), 'Sale held.');
    }

    private function validateSale(Request $request, bool $hold): array
    {
        return $request->validate([
            'store_id' => ['required', 'integer'], 'register_id' => ['nullable', 'integer'], 'shift_id' => ['nullable', 'integer'],
            'held_sale_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'], 'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'payments' => [$hold ? 'nullable' : 'required', 'array'], 'payments.*.method' => ['required_with:payments', Rule::in(['cash','card','bank_transfer','mobile','store_credit','credit'])],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'gt:0'], 'payments.*.tendered' => ['nullable', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
        ]);
    }

    public function salesReturn(Request $request)
    {
        $data = $request->validate([
            'sale_id' => ['required', 'integer'], 'store_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:255'],
            'refund_method' => ['required', Rule::in(['cash','card','bank_transfer','mobile','store_credit','exchange'])],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.sale_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.condition' => ['required', Rule::in(['saleable','damaged','expired'])],
        ]);
        return $this->ok($this->grocery->createReturn($request->user(), $data), 'Return posted.');
    }

    public function inventory(Request $request)
    {
        return $this->ok($this->grocery->inventory($request->user(), $request->integer('store_id') ?: null, $request->query('search')));
    }

    public function adjustStock(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'], 'reason' => ['required', Rule::in(['damage','spoilage','expiry','theft','correction','opening'])],
            'notes' => ['nullable', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'], 'lines.*.product_batch_id' => ['nullable', 'integer'],
            'lines.*.quantity_delta' => ['required', 'numeric', 'not_in:0'],
        ]);
        return $this->ok($this->grocery->adjustStock($request->user(), $data), 'Stock adjusted.');
    }

    public function receipts(Request $request)
    {
        return $this->ok(DB::table('goods_receipts as g')->join('suppliers as s', 's.id', '=', 'g.supplier_id')->where('g.branch_code', $request->user()->BC)->select('g.*', 's.name as supplier_name')->orderByDesc('g.received_at')->paginate(50));
    }

    public function purchaseOrders(Request $request)
    {
        return $this->ok(DB::table('grocery_purchase_orders as p')->join('suppliers as s', 's.id', '=', 'p.supplier_id')->where('p.branch_code', $request->user()->BC)->select('p.*', 's.name as supplier_name')->orderByDesc('p.order_date')->paginate(50));
    }

    public function storePurchaseOrder(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'], 'store_id' => ['required', 'integer'], 'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'], 'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'integer'], 'lines.*.unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'], 'lines.*.discount' => ['nullable', 'numeric', 'min:0'], 'lines.*.tax' => ['nullable', 'numeric', 'min:0'],
        ]);
        $user = $request->user();
        $order = DB::transaction(function () use ($data, $user) {
            if (! DB::table('suppliers')->where('id', $data['supplier_id'])->where('BC', $user->BC)->exists() || ! DB::table('stores')->where('id', $data['store_id'])->where('BC', $user->BC)->exists()) throw ValidationException::withMessages(['supplier_id' => 'Supplier or store is outside your branch.']);
            $subtotal = $discount = $tax = 0.0; $lines = [];
            foreach ($data['lines'] as $line) {
                $gross = (float) $line['quantity'] * (float) $line['unit_cost'];
                $lineDiscount = min($gross, (float) ($line['discount'] ?? 0)); $lineTax = (float) ($line['tax'] ?? 0);
                $lines[] = [...$line, 'line_total' => round($gross - $lineDiscount + $lineTax, 2)];
                $subtotal += $gross; $discount += $lineDiscount; $tax += $lineTax;
            }
            $id = DB::table('grocery_purchase_orders')->insertGetId([
                'order_no' => $this->grocery->nextNumber($user->BC, 'purchase_order'), 'branch_code' => $user->BC,
                'supplier_id' => $data['supplier_id'], 'store_id' => $data['store_id'], 'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null, 'status' => 'draft', 'subtotal' => round($subtotal, 2),
                'discount_total' => round($discount, 2), 'tax_total' => round($tax, 2), 'grand_total' => round($subtotal - $discount + $tax, 2),
                'notes' => $data['notes'] ?? null, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $line) DB::table('grocery_purchase_order_lines')->insert([
                'purchase_order_id' => $id, 'product_id' => $line['product_id'], 'unit_id' => $line['unit_id'],
                'conversion_factor' => DB::table('product_units')->where('product_id', $line['product_id'])->where('unit_id', $line['unit_id'])->value('conversion_factor') ?? 1,
                'ordered_quantity' => $line['quantity'], 'free_quantity' => $line['free_quantity'] ?? 0, 'received_quantity' => 0,
                'unit_cost' => $line['unit_cost'], 'discount_total' => $line['discount'] ?? 0, 'tax_total' => $line['tax'] ?? 0,
                'line_total' => $line['line_total'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->grocery->audit($user, 'create', 'purchase_order', $id, null, null, $data); return DB::table('grocery_purchase_orders')->find($id);
        });
        return $this->ok($order, 'Purchase order created.');
    }

    public function approvePurchaseOrder(Request $request, int $id)
    {
        $order = DB::table('grocery_purchase_orders')->where('id', $id)->where('branch_code', $request->user()->BC)->where('status', 'draft')->first();
        if (! $order) throw ValidationException::withMessages(['order' => 'Only draft purchase orders can be approved.']);
        DB::table('grocery_purchase_orders')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'updated_at' => now()]);
        $this->grocery->audit($request->user(), 'approve', 'purchase_order', $id);
        return $this->ok(DB::table('grocery_purchase_orders')->find($id), 'Purchase order approved.');
    }

    public function receiveGoods(Request $request)
    {
        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'integer'], 'supplier_id' => ['required', 'integer'], 'store_id' => ['required', 'integer'],
            'supplier_invoice_no' => ['required', 'string', 'max:100'], 'supplier_invoice_date' => ['required', 'date'], 'credit_purchase' => ['boolean'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.purchase_order_line_id' => ['nullable', 'integer'], 'lines.*.product_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.accepted_quantity' => ['nullable', 'numeric', 'min:0'], 'lines.*.rejected_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'], 'lines.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:80'], 'lines.*.manufactured_date' => ['nullable', 'date'], 'lines.*.expiry_date' => ['nullable', 'date'],
        ]);
        return $this->ok($this->grocery->receiveGoods($request->user(), $data), 'Goods receipt posted.');
    }

    public function purchaseReturn(Request $request)
    {
        $data = $request->validate([
            'goods_receipt_id' => ['nullable', 'integer'], 'supplier_id' => ['required', 'integer'], 'store_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.goods_receipt_line_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        return $this->ok($this->grocery->purchaseReturn($request->user(), $data), 'Purchase return posted.');
    }

    public function transfers(Request $request)
    {
        return $this->ok(DB::table('grocery_stock_transfers')->where('branch_code', $request->user()->BC)->orderByDesc('created_at')->paginate(50));
    }

    public function transferStock(Request $request)
    {
        $data = $request->validate(['from_store_id' => ['required', 'integer'], 'to_store_id' => ['required', 'integer'], 'notes' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'integer'], 'lines.*.product_batch_id' => ['nullable', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0']]);
        return $this->ok($this->grocery->transferStock($request->user(), $data), 'Transfer dispatched.');
    }

    public function receiveTransfer(Request $request, int $id) { return $this->ok($this->grocery->receiveTransfer($request->user(), $id), 'Transfer received.'); }

    public function stockCounts(Request $request)
    {
        return $this->ok(DB::table('stock_counts')->where('branch_code', $request->user()->BC)->orderByDesc('snapshot_at')->paginate(50));
    }

    public function stockCount(Request $request, int $id)
    {
        $count = DB::table('stock_counts')->where('id', $id)->where('branch_code', $request->user()->BC)->first();
        if (! $count) abort(404);
        $count->lines = DB::table('stock_count_lines as l')->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('product_batches as b', 'b.id', '=', 'l.product_batch_id')->where('l.stock_count_id', $id)
            ->select('l.*', 'p.sku', 'p.name as product_name', 'b.batch_no')->orderBy('p.name')->get();
        return $this->ok($count);
    }

    public function createStockCount(Request $request)
    {
        $data = $request->validate(['store_id' => ['required', 'integer'], 'type' => ['required', Rule::in(['full','cycle'])], 'product_ids' => ['nullable', 'array'], 'product_ids.*' => ['integer']]);
        return $this->ok($this->grocery->createStockCount($request->user(), $data), 'Stock count started.');
    }

    public function postStockCount(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.line_id' => ['required', 'integer'], 'lines.*.counted_quantity' => ['required', 'numeric', 'min:0']]);
        return $this->ok($this->grocery->postStockCount($request->user(), $id, $data), 'Stock count posted.');
    }

    public function cashMovement(Request $request)
    {
        $data = $request->validate(['shift_id' => ['nullable', 'integer'], 'type' => ['required', Rule::in(['cash_in','cash_out','cash_drop'])], 'amount' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:100']]);
        $id = DB::table('cash_movements')->insertGetId([...$data, 'branch_code' => $request->user()->BC, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->grocery->audit($request->user(), 'create', 'cash_movement', $id, $data['reason'], null, $data);
        return $this->ok(DB::table('cash_movements')->find($id), 'Cash movement recorded.');
    }

    public function expenses(Request $request)
    {
        return $this->ok(DB::table('grocery_expenses as e')->join('expense_categories as c', 'c.id', '=', 'e.category_id')->where('e.branch_code', $request->user()->BC)->select('e.*', 'c.name as category_name')->orderByDesc('e.expense_date')->paginate(50));
    }

    public function storeExpense(Request $request)
    {
        $data = $request->validate(['category_id' => ['required', 'integer'], 'expense_date' => ['required', 'date'], 'payee' => ['nullable', 'string', 'max:120'], 'amount' => ['required', 'numeric', 'gt:0'], 'payment_method' => ['required', Rule::in(['cash','card','bank_transfer','mobile'])], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string']]);
        $id = DB::transaction(function () use ($request, $data) {
            $id = DB::table('grocery_expenses')->insertGetId([...$data, 'expense_no' => $this->grocery->nextNumber($request->user()->BC, 'expense'), 'branch_code' => $request->user()->BC, 'status' => 'posted', 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->grocery->audit($request->user(), 'post', 'expense', $id, null, null, $data); return $id;
        });
        return $this->ok(DB::table('grocery_expenses')->find($id), 'Expense posted.');
    }

    public function supplierPayment(Request $request)
    {
        $data = $request->validate(['supplier_id' => ['required', 'integer'], 'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', Rule::in(['cash','card','bank_transfer','cheque'])], 'reference' => ['nullable', 'string', 'max:100']]);
        $user = $request->user();
        $payment = DB::transaction(function () use ($data, $user) {
            if (! DB::table('suppliers')->where('id', $data['supplier_id'])->where('BC', $user->BC)->exists()) throw ValidationException::withMessages(['supplier_id' => 'Supplier is outside your branch.']);
            $number = $this->grocery->nextNumber($user->BC, 'supplier_payment');
            $id = DB::table('grocery_supplier_payments')->insertGetId([...$data, 'payment_no' => $number, 'branch_code' => $user->BC, 'status' => 'posted', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('supplier_account_entries')->insert(['branch_code' => $user->BC, 'supplier_id' => $data['supplier_id'], 'reference_type' => 'supplier_payment', 'reference_no' => $number, 'entry_date' => $data['payment_date'], 'debit' => $data['amount'], 'credit' => 0, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->grocery->audit($user, 'post', 'supplier_payment', $id, null, null, $data); return DB::table('grocery_supplier_payments')->find($id);
        });
        return $this->ok($payment, 'Supplier payment posted.');
    }

    public function dashboard(Request $request) { return $this->ok($this->grocery->dashboard($request->user(), $request->query('from'), $request->query('to'))); }

    public function auditLog(Request $request)
    {
        return $this->ok(DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.branch_code', $request->user()->BC)->select('a.*', 'u.username')->orderByDesc('a.created_at')->paginate(100));
    }

    public function report(Request $request, string $report)
    {
        $branch = $request->user()->BC; $from = $request->query('from', today()->startOfMonth()->toDateString()); $to = $request->query('to', today()->toDateString());
        $data = match ($report) {
            'sales' => DB::table('sales')->where('branch_code', $branch)->whereBetween(DB::raw('date(sold_at)'), [$from, $to])->orderByDesc('sold_at')->get(),
            'profit' => DB::table('sales')->where('branch_code', $branch)->whereBetween(DB::raw('date(sold_at)'), [$from, $to])->selectRaw('date(sold_at) date, SUM(grand_total) sales, SUM(cost_total) cost, SUM(grand_total-cost_total) profit')->groupBy(DB::raw('date(sold_at)'))->get(),
            'inventory' => $this->grocery->inventory($request->user(), $request->integer('store_id') ?: null, $request->query('search')),
            'expiry' => DB::table('product_batches as b')->join('products as p', 'p.id', '=', 'b.product_id')->join('stores as s', 's.id', '=', 'b.store_id')->where('b.branch_code', $branch)->where('b.quantity', '>', 0)->select('b.*', 'p.sku', 'p.name', 's.name as store_name')->orderBy('b.expiry_date')->get(),
            'suppliers' => DB::table('supplier_account_entries as e')->join('suppliers as s', 's.id', '=', 'e.supplier_id')->where('e.branch_code', $branch)->selectRaw('s.id, s.Code code, s.name, SUM(e.credit-e.debit) balance')->groupBy('s.id', 's.Code', 's.name')->get(),
            'shifts' => DB::table('cashier_shifts')->where('branch_code', $branch)->whereBetween(DB::raw('date(opened_at)'), [$from, $to])->orderByDesc('opened_at')->get(),
            'expenses' => DB::table('grocery_expenses')->where('branch_code', $branch)->whereBetween('expense_date', [$from, $to])->orderByDesc('expense_date')->get(),
            'audit' => DB::table('audit_logs')->where('branch_code', $branch)->whereBetween(DB::raw('date(created_at)'), [$from, $to])->orderByDesc('created_at')->get(),
            default => abort(404),
        };
        return $this->ok($data);
    }
}
