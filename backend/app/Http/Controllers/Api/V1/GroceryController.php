<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GroceryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Models\RolePermission;

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
            'suppliers' => DB::table('suppliers')->where('BC', $branch)->where('active', true)
                ->select('id', 'name', 'Code')->orderBy('name')->limit(200)->get(),
            'customers' => DB::table('customers')->where('BC', $branch)->where('active', true)
                ->select('id', 'name', 'Code')->orderByRaw("CASE WHEN Code = 'WALK-IN' THEN 0 ELSE 1 END")
                ->orderBy('name')->limit(200)->get(),
            'promotions' => DB::table('promotions')->where(fn ($q) => $q->whereNull('branch_code')->orWhere('branch_code', $branch))->where('active', true)->get(),
            'company' => DB::table('branch_dels as b')->leftJoin('companies as c', 'c.id', '=', 'b.company_id')->where('b.bccode', $branch)->select('c.*')->first() ?: DB::table('companies')->first(),
            'expense_categories' => DB::table('expense_categories')->where(fn ($q) => $q->whereNull('branch_code')->orWhere('branch_code', $branch))->where('active', true)->orderBy('name')->get(),
            'open_shift' => DB::table('cashier_shifts')->where('branch_code', $branch)->where('cashier_id', $request->user()->id)->where('status', 'open')->first(),
            'settings' => DB::table('app_settings')->where(fn ($q) => $q->whereNull('branch_code')->orWhere('branch_code', $branch))->pluck('value', 'key'),
        ]);
    }

    public function products(Request $request)
    {
        return $this->ok($this->grocery->catalog(
            $request->user(),
            $request->query('search'),
            $request->integer('store_id') ?: null,
            min(1000, max(10, $request->integer('limit', $request->query('search') ? 100 : 100))),
            $request->boolean('include_inactive')
        ));
    }

    public function lookup(Request $request, string $resource)
    {
        $search = trim((string) $request->query('search', ''));
        $limit = min(100, max(10, $request->integer('limit', 30)));
        $branch = $request->user()->BC;

        $rows = match ($resource) {
            'products' => $this->grocery->catalog($request->user(), $search ?: null, $request->integer('store_id') ?: null, $limit),
            'customers' => DB::table('customers')->where('BC', $branch)->where('active', true)
                ->when($search, fn ($q) => $q->where(fn ($n) => $n->where('name', 'like', "{$search}%")
                    ->orWhere('Code', 'like', "{$search}%")->orWhere('phone', 'like', "{$search}%")))
                ->select('id', 'name', 'Code', 'phone')->orderByRaw("CASE WHEN Code = 'WALK-IN' THEN 0 ELSE 1 END")
                ->orderBy('name')->limit($limit)->get(),
            'suppliers' => DB::table('suppliers')->where('BC', $branch)->where('active', true)
                ->when($search, fn ($q) => $q->where(fn ($n) => $n->where('name', 'like', "{$search}%")
                    ->orWhere('Code', 'like', "{$search}%")->orWhere('phone', 'like', "{$search}%")))
                ->select('id', 'name', 'Code', 'phone')->orderBy('name')->limit($limit)->get(),
            default => abort(404),
        };

        return $this->ok($rows);
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
        return $this->ok(collect($this->grocery->catalog($user, null, null, 1000, true))->firstWhere('id', $id), 'Product created.');
    }

    public function updateProduct(Request $request, int $id)
    {
        $product = DB::table('products')->where('id', $id)->where('branch_code', $request->user()->BC)->first();
        if (! $product) abort(404);
        $data = $request->validate([
            'sku' => ['sometimes', 'required', 'string', 'max:50'], 'name' => ['sometimes', 'required', 'string', 'max:150'],
            'local_name' => ['nullable', 'string', 'max:150'], 'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'brand_id' => ['nullable', 'integer', 'exists:m_brands,id'],
            'base_unit_id' => ['sometimes', 'integer', 'exists:units,id'], 'tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],
            'preferred_supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'retail_price' => ['sometimes', 'numeric', 'min:0'], 'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'latest_cost' => ['sometimes', 'numeric', 'min:0'], 'reorder_level' => ['sometimes', 'numeric', 'min:0'],
            'batch_tracked' => ['sometimes', 'boolean'], 'expiry_tracked' => ['sometimes', 'boolean'],
            'weighted' => ['sometimes', 'boolean'], 'allow_decimal_qty' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'],
            'shelf_location' => ['nullable', 'string', 'max:80'], 'barcodes' => ['sometimes', 'array'],
            'barcodes.*' => ['string', 'max:80', 'distinct'],
        ]);
        DB::transaction(function () use ($request, $id, $product, $data) {
            if (isset($data['sku']) && DB::table('products')->where('branch_code', $request->user()->BC)->where('sku', $data['sku'])->where('id', '!=', $id)->exists()) {
                throw ValidationException::withMessages(['sku' => 'SKU is already used in this branch.']);
            }
            if (array_key_exists('barcodes', $data)) {
                foreach ($data['barcodes'] as $barcode) {
                    if (DB::table('product_barcodes')->where('barcode', trim($barcode))->where('product_id', '!=', $id)->exists()) {
                        throw ValidationException::withMessages(['barcodes' => "Barcode {$barcode} is already assigned to another product."]);
                    }
                }
                DB::table('product_barcodes')->where('product_id', $id)->delete();
                foreach (array_values($data['barcodes']) as $index => $barcode) DB::table('product_barcodes')->insert([
                    'product_id' => $id, 'barcode' => trim($barcode), 'primary' => $index === 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $productData = collect($data)->except('barcodes')->all();
            DB::table('products')->where('id', $id)->update([...$productData, 'updated_at' => now()]);
            if (isset($data['retail_price']) || isset($data['latest_cost']) || isset($data['base_unit_id'])) {
                $baseUnit = (int) ($data['base_unit_id'] ?? $product->base_unit_id);
                DB::table('product_units')->updateOrInsert(
                    ['product_id' => $id, 'unit_id' => $baseUnit],
                    ['conversion_factor' => 1, 'selling_price' => $data['retail_price'] ?? $product->retail_price,
                        'purchase_cost' => $data['latest_cost'] ?? $product->latest_cost, 'can_sell' => true, 'can_purchase' => true,
                        'created_at' => now(), 'updated_at' => now()]
                );
            }
            $this->grocery->audit($request->user(), 'update', 'product', $id, $request->input('reason'), $product, $data);
        });
        return $this->ok(collect($this->grocery->catalog($request->user(), null, null, 1000, true))->firstWhere('id', $id), 'Product updated.');
    }

    public function destroyProduct(Request $request, int $id)
    {
        $product = DB::table('products')->where('id', $id)->where('branch_code', $request->user()->BC)->first();
        if (! $product) abort(404);
        $used = DB::table('inventory_movements')->where('product_id', $id)->exists()
            || DB::table('sale_lines')->where('product_id', $id)->exists()
            || DB::table('goods_receipt_lines')->where('product_id', $id)->exists();
        if ($used) {
            DB::table('products')->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
            $this->grocery->audit($request->user(), 'deactivate', 'product', $id, 'Product has transaction history');
            return $this->ok(null, 'Product has transaction history and was deactivated instead of deleted.');
        }
        DB::table('products')->where('id', $id)->delete();
        $this->grocery->audit($request->user(), 'delete', 'product', $id, null, $product, null);
        return $this->ok(null, 'Product deleted.');
    }

    public function masterIndex(Request $request, string $resource)
    {
        $this->authorizeMaster($request, $resource, 'can_read');
        [$table, $branchColumn] = $this->masterResource($resource);
        $query = DB::table($table);
        if ($branchColumn) $query->where(fn ($q) => $q->whereNull($branchColumn)->orWhere($branchColumn, $request->user()->BC));
        if ($search = $request->query('search')) {
            $query->where(function ($builder) use ($resource, $search) {
                if ($resource === 'sequences') {
                    $builder->where('document_type', 'like', "%{$search}%")->orWhere('prefix', 'like', "%{$search}%");
                } else {
                    $builder->where('name', 'like', "%{$search}%");
                }
            });
        }
        return $this->ok($query->orderBy('id', 'desc')->paginate(min(100, max(10, $request->integer('per_page', 25)))));
    }

    public function masterStore(Request $request, string $resource)
    {
        $this->authorizeMaster($request, $resource, 'can_create');
        [$table, $branchColumn] = $this->masterResource($resource);
        $rules = $this->masterRules($resource);
        $data = $request->validate($rules);
        if ($resource === 'registers' && ! DB::table('stores')->where('id', $data['store_id'])->where('BC', $request->user()->BC)->exists()) throw ValidationException::withMessages(['store_id' => 'Store is outside your branch.']);
        if ($branchColumn) $data[$branchColumn] = $request->user()->BC;
        $id = DB::table($table)->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
        $this->grocery->audit($request->user(), 'create', $resource, $id, null, null, $data);
        return $this->ok(DB::table($table)->find($id), 'Saved.');
    }

    public function masterUpdate(Request $request, string $resource, int $id)
    {
        $this->authorizeMaster($request, $resource, 'can_update');
        [$table, $branchColumn] = $this->masterResource($resource);
        $record = DB::table($table)->where('id', $id)->when($branchColumn, fn ($q) => $q->where($branchColumn, $request->user()->BC))->first();
        if (! $record) abort(404);
        $data = $request->validate($this->masterRules($resource, $id));
        if ($resource === 'registers' && isset($data['store_id']) && ! DB::table('stores')->where('id', $data['store_id'])->where('BC', $request->user()->BC)->exists()) throw ValidationException::withMessages(['store_id' => 'Store is outside your branch.']);
        DB::table($table)->where('id', $id)->update([...$data, 'updated_at' => now()]);
        $this->grocery->audit($request->user(), 'update', $resource, $id, null, $record, $data);
        return $this->ok(DB::table($table)->find($id), 'Updated.');
    }

    public function masterDestroy(Request $request, string $resource, int $id)
    {
        $this->authorizeMaster($request, $resource, 'can_delete');
        [$table, $branchColumn] = $this->masterResource($resource);
        $record = DB::table($table)->where('id', $id)->when($branchColumn, fn ($q) => $q->where($branchColumn, $request->user()->BC))->first();
        if (! $record) abort(404);
        try { DB::table($table)->where('id', $id)->delete(); }
        catch (\Illuminate\Database\QueryException $exception) {
            if ($exception->getCode() === '23000') throw ValidationException::withMessages(['record' => 'This record is in use and cannot be deleted. Deactivate it instead.']);
            throw $exception;
        }
        $this->grocery->audit($request->user(), 'delete', $resource, $id, null, $record, null);
        return $this->ok(null, 'Deleted.');
    }

    private function masterRules(string $resource, ?int $id = null): array
    {
        return match ($resource) {
            'units' => ['code' => ['required', 'string', 'max:20', Rule::unique('units', 'code')->ignore($id)], 'name' => ['required', 'string', 'max:50'], 'decimal_places' => ['integer', 'between:0,6'], 'active' => ['boolean']],
            'tax-rates' => ['name' => ['required', 'string', 'max:50'], 'rate' => ['required', 'numeric', 'between:0,100'], 'inclusive' => ['boolean'], 'active' => ['boolean']],
            'registers' => ['code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:80'], 'store_id' => ['required', 'integer', 'exists:stores,id'], 'active' => ['boolean']],
            'promotions' => ['name' => ['required', 'string', 'max:120'], 'type' => ['required', Rule::in(['percentage','fixed','price','buy_x_get_y','quantity_break'])], 'target_type' => ['required', Rule::in(['product','category','brand','basket'])], 'target_id' => ['nullable', 'integer'], 'value' => ['required', 'numeric', 'min:0'], 'minimum_qty' => ['nullable', 'numeric', 'min:0'], 'minimum_subtotal' => ['nullable', 'numeric', 'min:0'], 'buy_qty' => ['nullable', 'numeric', 'min:0'], 'get_qty' => ['nullable', 'numeric', 'min:0'], 'priority' => ['integer', 'min:0'], 'stackable' => ['boolean'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'active' => ['boolean']],
            'expense-categories' => ['name' => ['required', 'string', 'max:100'], 'active' => ['boolean']],
            'accounts' => ['code' => ['required', 'string', 'max:30', Rule::unique('chart_accounts', 'code')->ignore($id)->where('branch_code', request()->user()->BC)], 'name' => ['required', 'string', 'max:120'], 'type' => ['required', Rule::in(['asset','liability','equity','income','expense'])], 'parent_id' => ['nullable', 'integer', 'exists:chart_accounts,id'], 'active' => ['boolean']],
            'sequences' => ['document_type' => ['required', Rule::in(['sale','return','shift','purchase_order','goods_receipt','purchase_return','transfer','adjustment','stock_count','supplier_payment','customer_payment','expense']), Rule::unique('document_sequences', 'document_type')->ignore($id)->where('branch_code', request()->user()->BC)], 'prefix' => ['required', 'string', 'max:20'], 'next_number' => ['required', 'integer', 'min:1']],
            default => [],
        };
    }

    private function masterResource(string $resource): array
    {
        return match ($resource) {
            'units' => ['units', null], 'tax-rates' => ['tax_rates', null],
            'registers' => ['registers', 'branch_code'], 'promotions' => ['promotions', 'branch_code'],
            'expense-categories' => ['expense_categories', 'branch_code'],
            'accounts' => ['chart_accounts', 'branch_code'],
            'sequences' => ['document_sequences', 'branch_code'],
            default => abort(404),
        };
    }

    private function authorizeMaster(Request $request, string $resource, string $action): void
    {
        if ($request->user()->isSuperAdmin()) return;
        $module = match ($resource) {
            'units' => 'units', 'tax-rates' => 'taxes', 'registers' => 'registers',
            'promotions' => 'promotions', 'expense-categories' => 'expenses', 'accounts' => 'accounts',
            'sequences' => 'settings',
            default => abort(404),
        };
        if (! RolePermission::where('role_id', $request->user()->role_id)->where('module', $module)->where($action, true)->exists()) {
            abort(403, "You do not have permission to {$action} {$module}.");
        }
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
            ->when($request->query('search'), fn ($q, $v) => $q->where(fn ($n) => $n->where('invoice_no', 'like', "{$v}%")->orWhere('hold_reference', 'like', "{$v}%")))
            ->when($request->query('from'), fn ($q, $v) => $q->where('sold_at', '>=', "{$v} 00:00:00"))
            ->when($request->query('to'), fn ($q, $v) => $q->where('sold_at', '<=', "{$v} 23:59:59"));
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
            'bill_discount_type' => ['nullable', Rule::in(['amount', 'percent'])],
            'bill_discount_value' => ['nullable', 'numeric', 'min:0'],
            'hold_reference' => ['nullable', 'string', 'max:20'],
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
        return $this->ok(DB::table('goods_receipts as g')->join('suppliers as s', 's.id', '=', 'g.supplier_id')
            ->where('g.branch_code', $request->user()->BC)
            ->when($request->query('search'), fn ($q, $v) => $q->where(fn ($n) => $n->where('g.receipt_no', 'like', "{$v}%")->orWhere('g.supplier_invoice_no', 'like', "{$v}%")->orWhere('s.name', 'like', "{$v}%")))
            ->select('g.*', 's.name as supplier_name')->orderByDesc('g.received_at')->paginate(50));
    }

    public function purchaseOrders(Request $request)
    {
        return $this->ok(DB::table('grocery_purchase_orders as p')->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.branch_code', $request->user()->BC)
            ->when($request->query('search'), fn ($q, $v) => $q->where(fn ($n) => $n->where('p.order_no', 'like', "{$v}%")->orWhere('s.name', 'like', "{$v}%")))
            ->select('p.*', 's.name as supplier_name')->orderByDesc('p.order_date')->paginate(50));
    }

    public function storePurchaseOrder(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'], 'store_id' => ['required', 'integer'], 'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'], 'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'min:1', Rule::exists('products', 'id')->where('branch_code', $request->user()->BC)],
            'lines.*.unit_id' => ['required', 'integer', 'min:1', 'exists:units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'], 'lines.*.discount' => ['nullable', 'numeric', 'min:0'], 'lines.*.tax' => ['nullable', 'numeric', 'min:0'],
        ]);
        $user = $request->user();
        $order = DB::transaction(function () use ($data, $user) {
            if (! DB::table('suppliers')->where('id', $data['supplier_id'])->where('BC', $user->BC)->exists() || ! DB::table('stores')->where('id', $data['store_id'])->where('BC', $user->BC)->exists()) throw ValidationException::withMessages(['supplier_id' => 'Supplier or store is outside your branch.']);
            $subtotal = $discount = $tax = 0.0; $lines = [];
            foreach ($data['lines'] as $line) {
                if (! DB::table('product_units')->where('product_id', $line['product_id'])->where('unit_id', $line['unit_id'])->exists()) {
                    throw ValidationException::withMessages(['lines' => 'The selected purchase unit is not configured for one of the products.']);
                }
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
            'lines' => ['required', 'array', 'min:1'], 'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.product_id' => ['required', 'integer', 'min:1', Rule::exists('products', 'id')->where('branch_code', $request->user()->BC)],
            'lines.*.unit_id' => ['required', 'integer', 'min:1', 'exists:units,id'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.free_quantity' => ['nullable', 'numeric', 'min:0'],
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
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'], 'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash','card','bank_transfer','cheque'])], 'reference' => ['nullable', 'string', 'max:100'],
            'cheque_no' => ['nullable', 'required_if:method,cheque', 'string', 'max:80'],
            'bank_name' => ['nullable', 'required_if:method,cheque', 'string', 'max:120'],
            'cheque_date' => ['nullable', 'required_if:method,cheque', 'date'],
        ]);
        $user = $request->user();
        $payment = DB::transaction(function () use ($data, $user) {
            if (! DB::table('suppliers')->where('id', $data['supplier_id'])->where('BC', $user->BC)->exists()) throw ValidationException::withMessages(['supplier_id' => 'Supplier is outside your branch.']);
            if ($data['method'] === 'cheque') {
                $enabled = (bool) (DB::table('branch_dels as b')->leftJoin('companies as c', 'c.id', '=', 'b.company_id')->where('b.bccode', $user->BC)->value('c.post_dated_cheques_enabled') ?? false);
                if (! $enabled) throw ValidationException::withMessages(['method' => 'Post-dated cheques are disabled in Company Settings.']);
            }
            $number = $this->grocery->nextNumber($user->BC, 'supplier_payment');
            $paymentData = collect($data)->only(['supplier_id','payment_date','amount','method','reference'])->all();
            $id = DB::table('grocery_supplier_payments')->insertGetId([...$paymentData, 'payment_no' => $number, 'branch_code' => $user->BC, 'status' => 'posted', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            if ($data['method'] === 'cheque') DB::table('payment_cheques')->insert([
                'branch_code' => $user->BC, 'direction' => 'outgoing', 'reference_type' => 'supplier_payment', 'reference_id' => $id,
                'cheque_no' => $data['cheque_no'], 'bank_name' => $data['bank_name'], 'cheque_date' => $data['cheque_date'],
                'amount' => $data['amount'], 'status' => 'pending', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('supplier_account_entries')->insert(['branch_code' => $user->BC, 'supplier_id' => $data['supplier_id'], 'reference_type' => 'supplier_payment', 'reference_no' => $number, 'entry_date' => $data['payment_date'], 'debit' => $data['amount'], 'credit' => 0, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->grocery->audit($user, 'post', 'supplier_payment', $id, null, null, $data); return DB::table('grocery_supplier_payments')->find($id);
        });
        return $this->ok($payment, 'Supplier payment posted.');
    }

    public function cheques(Request $request)
    {
        return $this->ok(DB::table('payment_cheques')->where('branch_code', $request->user()->BC)->orderBy('cheque_date')->paginate(100));
    }

    public function updateCheque(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['cleared','returned','cancelled'])], 'reason' => ['required', 'string', 'max:255']]);
        $cheque = DB::table('payment_cheques')->where('id', $id)->where('branch_code', $request->user()->BC)->first();
        if (! $cheque || $cheque->status !== 'pending') throw ValidationException::withMessages(['cheque' => 'Only pending cheques can be updated.']);
        DB::table('payment_cheques')->where('id', $id)->update(['status' => $data['status'], 'updated_by' => $request->user()->id, 'updated_at' => now()]);
        $this->grocery->audit($request->user(), $data['status'], 'payment_cheque', $id, $data['reason'], $cheque, ['status' => $data['status']]);
        return $this->ok(DB::table('payment_cheques')->find($id), 'Cheque status updated.');
    }

    public function customerAccounts(Request $request)
    {
        return $this->ok(DB::table('customers as c')
            ->leftJoin('customer_account_entries as e', 'e.customer_id', '=', 'c.id')
            ->where('c.BC', $request->user()->BC)
            ->select('c.id', 'c.Code as code', 'c.name', 'c.phone', 'c.credit_limit', 'c.advance_balance', 'c.active')
            ->selectRaw('COALESCE(SUM(e.debit - e.credit), 0) as credit_balance')
            ->groupBy('c.id', 'c.Code', 'c.name', 'c.phone', 'c.credit_limit', 'c.advance_balance', 'c.active')
            ->orderBy('c.name')->paginate(100));
    }

    public function customerPayment(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'], 'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash','card','bank_transfer','mobile'])],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        $result = DB::transaction(function () use ($data, $user) {
            $company = DB::table('branch_dels as b')->leftJoin('companies as c', 'c.id', '=', 'b.company_id')
                ->where('b.bccode', $user->BC)->select('c.customer_credit_enabled')->first();
            if (! $company?->customer_credit_enabled) throw ValidationException::withMessages(['customer_id' => 'Customer credit is disabled in Company Settings.']);
            $customer = DB::table('customers')->where('id', $data['customer_id'])->where('BC', $user->BC)->where('active', true)->lockForUpdate()->first();
            if (! $customer) throw ValidationException::withMessages(['customer_id' => 'Select an active customer in your branch.']);
            $balance = max(0, (float) DB::table('customer_account_entries')->where('customer_id', $customer->id)->sum(DB::raw('debit - credit')));
            $amount = round((float) $data['amount'], 2); $applied = round(min($balance, $amount), 2); $advance = round($amount - $applied, 2);
            $number = $this->grocery->nextNumber($user->BC, 'customer_payment');
            $entryId = null;
            if ($applied > 0) $entryId = DB::table('customer_account_entries')->insertGetId([
                'branch_code' => $user->BC, 'customer_id' => $customer->id, 'reference_type' => 'customer_payment',
                'reference_no' => $number, 'entry_date' => $data['payment_date'], 'debit' => 0, 'credit' => $applied,
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($advance > 0) DB::table('customers')->where('id', $customer->id)->increment('advance_balance', $advance, ['updated_at' => now()]);
            $result = ['id' => $entryId, 'payment_no' => $number, 'customer_id' => $customer->id, 'amount' => $amount,
                'applied_to_credit' => $applied, 'added_store_credit' => $advance, 'method' => $data['method'], 'reference' => $data['reference'] ?? null];
            $this->grocery->audit($user, 'post', 'customer_payment', $entryId ?? $customer->id, null, null, $result);
            return $result;
        });
        return $this->ok($result, 'Customer payment recorded.');
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
