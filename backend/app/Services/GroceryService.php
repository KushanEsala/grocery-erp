<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GroceryService
{
    public function nextNumber(string $branch, string $type): string
    {
        $sequence = DB::table('document_sequences')
            ->where('branch_code', $branch)
            ->where('document_type', $type)
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $type), 0, 4));
            DB::table('document_sequences')->insert([
                'branch_code' => $branch, 'document_type' => $type, 'prefix' => $prefix,
                'next_number' => 2, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return sprintf('%s-%s-%06d', $prefix, $branch, 1);
        }

        DB::table('document_sequences')->where('id', $sequence->id)->update([
            'next_number' => $sequence->next_number + 1, 'updated_at' => now(),
        ]);

        return sprintf('%s-%s-%06d', $sequence->prefix, $branch, $sequence->next_number);
    }

    public function audit(User $user, string $action, string $entity, string|int|null $id, ?string $reason = null, mixed $before = null, mixed $after = null): void
    {
        DB::table('audit_logs')->insert([
            'branch_code' => $user->BC, 'user_id' => $user->id, 'action' => $action,
            'entity_type' => $entity, 'entity_id' => $id, 'reason' => $reason,
            'before_values' => $before === null ? null : json_encode($before),
            'after_values' => $after === null ? null : json_encode($after),
            'ip_address' => request()->ip(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function catalog(User $user, ?string $search = null, ?int $storeId = null): array
    {
        $storeId ??= (int) DB::table('stores')->where('BC', $user->BC)->value('id');
        $products = DB::table('products as p')
            ->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->leftJoin('tax_rates as t', 't.id', '=', 'p.tax_rate_id')
            ->where('p.branch_code', $user->BC)
            ->where('p.active', true)
            ->when($search, function ($query, $search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('p.sku', 'like', "%{$search}%")
                        ->orWhere('p.name', 'like', "%{$search}%")
                        ->orWhereExists(function ($barcode) use ($search) {
                            $barcode->selectRaw('1')->from('product_barcodes as pb')
                                ->whereColumn('pb.product_id', 'p.id')->where('pb.barcode', $search);
                        });
                });
            })
            ->select('p.*', 'u.code as base_unit_code', 'u.name as base_unit_name', 't.rate as tax_rate', 't.inclusive as tax_inclusive')
            ->orderBy('p.name')->limit($search ? 50 : 200)->get();

        return $products->map(function ($product) use ($storeId) {
            $product->barcodes = DB::table('product_barcodes')->where('product_id', $product->id)->pluck('barcode');
            $product->units = DB::table('product_units as pu')->join('units as u', 'u.id', '=', 'pu.unit_id')
                ->where('pu.product_id', $product->id)
                ->select('pu.*', 'u.code', 'u.name', 'u.decimal_places')->get();
            $product->stock = $this->stockQuantity($product->id, $storeId);
            $product->batches = DB::table('product_batches')->where('product_id', $product->id)
                ->where('store_id', $storeId)->where('quantity', '>', 0)
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')
                ->get();
            return $product;
        })->all();
    }

    public function stockQuantity(int $productId, int $storeId): float
    {
        return (float) DB::table('inventory_movements')->where('product_id', $productId)
            ->where('store_id', $storeId)->selectRaw('COALESCE(SUM(quantity_in - quantity_out), 0) quantity')
            ->value('quantity');
    }

    public function inventory(User $user, ?int $storeId = null, ?string $search = null): array
    {
        $rows = DB::table('products as p')->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.branch_code', $user->BC)
            ->when($search, fn ($q, $s) => $q->where(fn ($n) => $n->where('p.sku', 'like', "%{$s}%")->orWhere('p.name', 'like', "%{$s}%")))
            ->select('p.id', 'p.sku', 'p.name', 'p.reorder_level', 'p.average_cost', 'p.expiry_tracked', 'u.code as unit', 'c.name as category')
            ->orderBy('p.name')->get();

        return $rows->map(function ($row) use ($user, $storeId) {
            $movement = DB::table('inventory_movements')->where('branch_code', $user->BC)
                ->where('product_id', $row->id)->when($storeId, fn ($q, $id) => $q->where('store_id', $id));
            $row->quantity = (float) (clone $movement)->selectRaw('COALESCE(SUM(quantity_in - quantity_out), 0) q')->value('q');
            $row->stock_value = round($row->quantity * (float) $row->average_cost, 2);
            $row->low_stock = $row->quantity <= (float) $row->reorder_level;
            $row->near_expiry = DB::table('product_batches')->where('branch_code', $user->BC)
                ->where('product_id', $row->id)->when($storeId, fn ($q, $id) => $q->where('store_id', $id))
                ->where('quantity', '>', 0)->whereBetween('expiry_date', [today(), today()->addDays(30)])->count();
            return $row;
        })->all();
    }

    public function openShift(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            $register = DB::table('registers')->where('id', $data['register_id'])
                ->where('branch_code', $user->BC)->where('active', true)->first();
            if (! $register) {
                throw ValidationException::withMessages(['register_id' => 'Select an active register in your branch.']);
            }
            if (DB::table('cashier_shifts')->where('register_id', $register->id)->where('status', 'open')->exists()) {
                throw ValidationException::withMessages(['register_id' => 'This register already has an open shift.']);
            }
            $id = DB::table('cashier_shifts')->insertGetId([
                'shift_no' => $this->nextNumber($user->BC, 'shift'), 'branch_code' => $user->BC,
                'register_id' => $register->id, 'cashier_id' => $user->id, 'opened_at' => now(),
                'opening_float' => round((float) $data['opening_float'], 2), 'status' => 'open',
                'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($user, 'open', 'cashier_shift', $id, null, null, $data);
            return DB::table('cashier_shifts')->find($id);
        });
    }

    public function closeShift(User $user, int $id, array $data): object
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $shift = DB::table('cashier_shifts')->where('id', $id)->where('branch_code', $user->BC)->lockForUpdate()->first();
            if (! $shift || $shift->status !== 'open') {
                throw ValidationException::withMessages(['shift' => 'The shift is not open.']);
            }
            $cashSales = (float) DB::table('sale_payments as sp')->join('sales as s', 's.id', '=', 'sp.sale_id')
                ->where('s.shift_id', $id)->where('s.status', '!=', 'voided')->where('sp.method', 'cash')->sum('sp.amount');
            $cashMoves = (float) DB::table('cash_movements')->where('shift_id', $id)
                ->selectRaw("COALESCE(SUM(CASE WHEN type = 'cash_in' THEN amount ELSE -amount END), 0) total")->value('total');
            $expected = round((float) $shift->opening_float + $cashSales + $cashMoves, 2);
            $counted = round((float) $data['counted_cash'], 2);
            DB::table('cashier_shifts')->where('id', $id)->update([
                'closed_at' => now(), 'expected_cash' => $expected, 'counted_cash' => $counted,
                'variance' => round($counted - $expected, 2), 'status' => 'closed',
                'notes' => $data['notes'] ?? $shift->notes, 'approved_by' => $data['approved_by'] ?? null,
                'updated_at' => now(),
            ]);
            $after = DB::table('cashier_shifts')->find($id);
            $this->audit($user, 'close', 'cashier_shift', $id, $data['reason'] ?? null, $shift, $after);
            return $after;
        });
    }

    public function completeSale(User $user, array $data, bool $hold = false): object
    {
        return DB::transaction(function () use ($user, $data, $hold) {
            $heldSale = null;
            if (! $hold && ($data['held_sale_id'] ?? null)) {
                $heldSale = DB::table('sales')->where('id', $data['held_sale_id'])
                    ->where('branch_code', $user->BC)->where('status', 'held')->lockForUpdate()->first();
                if (! $heldSale) {
                    throw ValidationException::withMessages(['held_sale_id' => 'The held sale is no longer available.']);
                }
            }
            $store = DB::table('stores')->where('id', $data['store_id'])->where('BC', $user->BC)->first();
            if (! $store) {
                throw ValidationException::withMessages(['store_id' => 'Select a store in your branch.']);
            }
            $shift = null;
            if (! $hold && ($data['shift_id'] ?? null)) {
                $shift = DB::table('cashier_shifts')->where('id', $data['shift_id'])->where('branch_code', $user->BC)->where('status', 'open')->first();
            }
            $requiresShift = DB::table('app_settings')->where('branch_code', $user->BC)->where('key', 'require_open_shift')->value('value') !== 'false';
            if (! $hold && $requiresShift && ! $shift) {
                throw ValidationException::withMessages(['shift_id' => 'Open a cashier shift before completing a sale.']);
            }

            $prepared = [];
            $subtotal = $discountTotal = $taxTotal = $costTotal = $grandTotal = 0.0;
            foreach ($data['lines'] as $index => $line) {
                $product = DB::table('products')->where('id', $line['product_id'])->where('branch_code', $user->BC)->where('active', true)->first();
                if (! $product) throw ValidationException::withMessages(["lines.{$index}.product_id" => 'Product is unavailable.']);
                $unit = DB::table('product_units as pu')->join('units as u', 'u.id', '=', 'pu.unit_id')
                    ->where('pu.product_id', $product->id)->where('pu.unit_id', $line['unit_id'])->select('pu.*', 'u.code', 'u.decimal_places')->first();
                if (! $unit) throw ValidationException::withMessages(["lines.{$index}.unit_id" => 'Selling unit is invalid.']);
                $qty = round((float) $line['quantity'], 6);
                if ($qty <= 0 || (! $product->allow_decimal_qty && fmod($qty, 1.0) !== 0.0)) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Enter a valid quantity for this product.']);
                }
                $baseQty = round($qty * (float) $unit->conversion_factor, 6);
                $price = round((float) ($line['unit_price'] ?? $unit->selling_price ?? $product->retail_price), 4);
                $gross = round($qty * $price, 2);
                [$promotionId, $promotionDiscount] = $this->promotionDiscount($user->BC, $product, $qty, $gross);
                $manualDiscount = round((float) ($line['discount'] ?? 0), 2);
                $discount = min($gross, max($promotionDiscount, $manualDiscount));
                $taxRate = (float) (DB::table('tax_rates')->where('id', $product->tax_rate_id)->value('rate') ?? 0);
                $inclusive = (bool) (DB::table('tax_rates')->where('id', $product->tax_rate_id)->value('inclusive') ?? true);
                $afterDiscount = $gross - $discount;
                $tax = $inclusive ? round($afterDiscount - ($afterDiscount / (1 + $taxRate / 100)), 2) : round($afterDiscount * $taxRate / 100, 2);
                $total = $inclusive ? round($afterDiscount, 2) : round($afterDiscount + $tax, 2);
                $allocations = $hold ? [['batch_id' => null, 'quantity' => $baseQty]] : $this->allocateStock($user, $store->id, $product, $baseQty);
                $prepared[] = compact('product', 'unit', 'qty', 'baseQty', 'price', 'gross', 'discount', 'tax', 'total', 'promotionId', 'allocations');
                $subtotal += $gross; $discountTotal += $discount; $taxTotal += $tax;
                $costTotal += $baseQty * (float) $product->average_cost; $grandTotal += $total;
            }
            $grand = round($grandTotal, 2);
            if (! $hold) {
                $paid = round(collect($data['payments'])->sum(fn ($p) => (float) $p['amount']), 2);
                if ($paid + 0.009 < $grand) throw ValidationException::withMessages(['payments' => 'Payments must cover the sale total.']);
            }

            $saleId = DB::table('sales')->insertGetId([
                'invoice_no' => $this->nextNumber($user->BC, 'sale'), 'branch_code' => $user->BC,
                'store_id' => $store->id, 'register_id' => $data['register_id'] ?? $shift?->register_id,
                'shift_id' => $shift?->id, 'customer_id' => $data['customer_id'] ?? null, 'sold_at' => now(),
                'status' => $hold ? 'held' : 'completed', 'subtotal' => round($subtotal, 2),
                'discount_total' => round($discountTotal, 2), 'tax_total' => round($taxTotal, 2),
                'grand_total' => $grand, 'cost_total' => round($costTotal, 2), 'notes' => $data['notes'] ?? null,
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($prepared as $row) {
                foreach ($row['allocations'] as $allocation) {
                    $ratio = $row['baseQty'] > 0 ? $allocation['quantity'] / $row['baseQty'] : 1;
                    $lineId = DB::table('sale_lines')->insertGetId([
                        'sale_id' => $saleId, 'product_id' => $row['product']->id, 'product_batch_id' => $allocation['batch_id'],
                        'unit_id' => $row['unit']->unit_id, 'sku' => $row['product']->sku, 'description' => $row['product']->name,
                        'unit_code' => $row['unit']->code, 'conversion_factor' => $row['unit']->conversion_factor,
                        'quantity' => round($row['qty'] * $ratio, 6), 'base_quantity' => $allocation['quantity'],
                        'unit_price' => $row['price'], 'gross_total' => round($row['gross'] * $ratio, 2),
                        'discount_total' => round($row['discount'] * $ratio, 2), 'tax_total' => round($row['tax'] * $ratio, 2),
                        'line_total' => round($row['total'] * $ratio, 2), 'unit_cost' => $row['product']->average_cost,
                        'promotion_id' => $row['promotionId'], 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (! $hold) $this->moveStock($user, $store->id, $row['product']->id, $allocation['batch_id'], 'sale', (string) $saleId, 0, $allocation['quantity'], $row['product']->average_cost);
                }
            }

            if (! $hold) {
                $remaining = $grand;
                foreach ($data['payments'] as $payment) {
                    $applied = min($remaining, round((float) $payment['amount'], 2));
                    DB::table('sale_payments')->insert([
                        'sale_id' => $saleId, 'method' => $payment['method'], 'amount' => $applied,
                        'reference' => $payment['reference'] ?? null, 'tendered' => $payment['tendered'] ?? $payment['amount'],
                        'change_amount' => max(0, round((float) ($payment['tendered'] ?? $payment['amount']) - $applied, 2)),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $remaining = round($remaining - $applied, 2);
                }
            }
            if ($heldSale) {
                DB::table('sales')->where('id', $heldSale->id)->update([
                    'status' => 'voided', 'voided_by' => $user->id,
                    'void_reason' => 'Resumed as '.DB::table('sales')->where('id', $saleId)->value('invoice_no'),
                    'updated_at' => now(),
                ]);
                $this->audit($user, 'resume', 'sale', $heldSale->id, null, $heldSale, ['completed_sale_id' => $saleId]);
            }
            $this->audit($user, $hold ? 'hold' : 'complete', 'sale', $saleId, null, null, ['total' => $grand]);
            return $this->saleDetail($user, $saleId);
        });
    }

    private function allocateStock(User $user, int $storeId, object $product, float $quantity): array
    {
        if (! $product->batch_tracked) {
            if ($this->stockQuantity($product->id, $storeId) + 0.000001 < $quantity) {
                throw ValidationException::withMessages(['stock' => "Insufficient stock for {$product->name}."]);
            }
            return [['batch_id' => null, 'quantity' => $quantity]];
        }
        $remaining = $quantity; $allocations = [];
        $batches = DB::table('product_batches')->where('branch_code', $user->BC)->where('store_id', $storeId)
            ->where('product_id', $product->id)->where('quantity', '>', 0)
            ->when($product->expiry_tracked, fn ($q) => $q->where(fn ($n) => $n->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', today())))
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->lockForUpdate()->get();
        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $take = min($remaining, (float) $batch->quantity);
            $allocations[] = ['batch_id' => $batch->id, 'quantity' => $take];
            $remaining = round($remaining - $take, 6);
        }
        if ($remaining > 0.000001) throw ValidationException::withMessages(['stock' => "Insufficient eligible stock for {$product->name}."]);
        return $allocations;
    }

    private function promotionDiscount(string $branch, object $product, float $qty, float $gross): array
    {
        $now = now();
        $promotions = DB::table('promotions')->where('active', true)
            ->where(fn ($q) => $q->whereNull('branch_code')->orWhere('branch_code', $branch))
            ->where('starts_at', '<=', $now)->where('ends_at', '>=', $now)
            ->where(fn ($q) => $q->where('target_type', 'basket')->orWhere(fn ($n) => $n->where('target_type', 'product')->where('target_id', $product->id))->orWhere(fn ($n) => $n->where('target_type', 'category')->where('target_id', $product->category_id))->orWhere(fn ($n) => $n->where('target_type', 'brand')->where('target_id', $product->brand_id)))
            ->where(fn ($q) => $q->whereNull('minimum_qty')->orWhere('minimum_qty', '<=', $qty))
            ->where(fn ($q) => $q->whereNull('minimum_subtotal')->orWhere('minimum_subtotal', '<=', $gross))
            ->orderBy('priority')->get();
        $bestId = null; $best = 0.0;
        foreach ($promotions as $promotion) {
            $discount = match ($promotion->type) {
                'percentage' => $gross * (float) $promotion->value / 100,
                'fixed' => (float) $promotion->value,
                'price' => max(0, $gross - ($qty * (float) $promotion->value)),
                'buy_x_get_y' => ($promotion->buy_qty && $promotion->get_qty) ? floor($qty / ((float) $promotion->buy_qty + (float) $promotion->get_qty)) * (float) $promotion->get_qty * ($gross / $qty) : 0,
                'quantity_break' => $gross * (float) $promotion->value / 100,
                default => 0,
            };
            if ($discount > $best) { $best = $discount; $bestId = $promotion->id; }
        }
        return [$bestId, round(min($gross, $best), 2)];
    }

    private function moveStock(User $user, int $storeId, int $productId, ?int $batchId, string $type, string $reference, float $in, float $out, float $cost): void
    {
        if ($batchId) {
            DB::table('product_batches')->where('id', $batchId)->increment('quantity', $in - $out, ['updated_at' => now()]);
        }
        DB::table('inventory_movements')->insert([
            'branch_code' => $user->BC, 'store_id' => $storeId, 'product_id' => $productId,
            'product_batch_id' => $batchId, 'transaction_type' => $type, 'reference_no' => $reference,
            'quantity_in' => $in, 'quantity_out' => $out, 'unit_cost' => $cost,
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function saleDetail(User $user, int $id): object
    {
        $sale = DB::table('sales as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('stores as st', 'st.id', '=', 's.store_id')
            ->leftJoin('registers as r', 'r.id', '=', 's.register_id')
            ->where('s.id', $id)->where('s.branch_code', $user->BC)
            ->select('s.*', 'c.name as customer_name', 'st.name as store_name', 'r.name as register_name')
            ->first();
        if (! $sale) abort(404);
        $sale->lines = DB::table('sale_lines')->where('sale_id', $id)->get();
        $sale->payments = DB::table('sale_payments')->where('sale_id', $id)->get();
        return $sale;
    }

    public function voidSale(User $user, int $id, string $reason): object
    {
        return DB::transaction(function () use ($user, $id, $reason) {
            $sale = DB::table('sales')->where('id', $id)->where('branch_code', $user->BC)->lockForUpdate()->first();
            if (! $sale || $sale->status !== 'completed') {
                throw ValidationException::withMessages(['sale' => 'Only a completed, unreturned sale can be voided.']);
            }
            if (DB::table('sales_returns')->where('sale_id', $id)->exists()) {
                throw ValidationException::withMessages(['sale' => 'This sale already has a return; use the return workflow for the remaining items.']);
            }
            $lines = DB::table('sale_lines')->where('sale_id', $id)->get();
            foreach ($lines as $line) {
                $this->moveStock($user, $sale->store_id, $line->product_id, $line->product_batch_id, 'sale_void', (string) $id, (float) $line->base_quantity, 0, (float) $line->unit_cost);
            }
            DB::table('sales')->where('id', $id)->update([
                'status' => 'voided', 'voided_by' => $user->id, 'void_reason' => $reason, 'updated_at' => now(),
            ]);
            $after = DB::table('sales')->find($id);
            $this->audit($user, 'void', 'sale', $id, $reason, $sale, $after);
            return $this->saleDetail($user, $id);
        });
    }

    public function receiveGoods(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            $supplier = DB::table('suppliers')->where('id', $data['supplier_id'])->where('BC', $user->BC)->first();
            $store = DB::table('stores')->where('id', $data['store_id'])->where('BC', $user->BC)->first();
            if (! $supplier || ! $store) throw ValidationException::withMessages(['supplier_id' => 'Supplier or store is outside your branch.']);
            $receiptNo = $this->nextNumber($user->BC, 'goods_receipt');
            $receiptId = DB::table('goods_receipts')->insertGetId([
                'receipt_no' => $receiptNo, 'branch_code' => $user->BC, 'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'supplier_id' => $supplier->id, 'store_id' => $store->id, 'supplier_invoice_no' => $data['supplier_invoice_no'],
                'supplier_invoice_date' => $data['supplier_invoice_date'], 'received_at' => now(), 'status' => 'posted',
                'grand_total' => 0, 'credit_purchase' => $data['credit_purchase'] ?? true, 'created_by' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $total = 0.0;
            foreach ($data['lines'] as $index => $line) {
                $product = DB::table('products')->where('id', $line['product_id'])->where('branch_code', $user->BC)->lockForUpdate()->first();
                if (! $product) throw ValidationException::withMessages(["lines.{$index}.product_id" => 'Product is unavailable.']);
                $unit = DB::table('product_units')->where('product_id', $product->id)->where('unit_id', $line['unit_id'])->first();
                if (! $unit) throw ValidationException::withMessages(["lines.{$index}.unit_id" => 'Purchase unit is invalid.']);
                $qty = (float) $line['quantity']; $free = (float) ($line['free_quantity'] ?? 0); $accepted = (float) ($line['accepted_quantity'] ?? $qty);
                $baseQty = round(($accepted + $free) * (float) $unit->conversion_factor, 6);
                $unitCost = round((float) $line['unit_cost'] / (float) $unit->conversion_factor, 4);
                if ($product->expiry_tracked && empty($line['expiry_date'])) throw ValidationException::withMessages(["lines.{$index}.expiry_date" => 'Expiry date is required.']);
                if ($product->expiry_tracked && Carbon::parse($line['expiry_date'])->lt(today())) throw ValidationException::withMessages(["lines.{$index}.expiry_date" => 'Expired stock cannot be received.']);
                $batchId = null;
                if ($product->batch_tracked) {
                    if (empty($line['batch_no'])) throw ValidationException::withMessages(["lines.{$index}.batch_no" => 'Batch number is required.']);
                    $batch = DB::table('product_batches')->where('branch_code', $user->BC)->where('store_id', $store->id)
                        ->where('product_id', $product->id)->where('batch_no', $line['batch_no'])->lockForUpdate()->first();
                    if ($batch) { $batchId = $batch->id; }
                    else {
                        $batchId = DB::table('product_batches')->insertGetId([
                            'branch_code' => $user->BC, 'store_id' => $store->id, 'product_id' => $product->id,
                            'batch_no' => $line['batch_no'], 'manufactured_date' => $line['manufactured_date'] ?? null,
                            'expiry_date' => $line['expiry_date'] ?? null, 'quantity' => 0, 'unit_cost' => $unitCost,
                            'selling_price' => $line['selling_price'] ?? $product->retail_price, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
                $oldQty = DB::table('inventory_movements')->where('product_id', $product->id)->selectRaw('COALESCE(SUM(quantity_in - quantity_out),0) q')->value('q');
                $newAverage = ((float) $oldQty + $baseQty) > 0 ? (((float) $oldQty * (float) $product->average_cost) + ($baseQty * $unitCost)) / ((float) $oldQty + $baseQty) : $unitCost;
                $productUpdate = ['latest_cost' => $unitCost, 'average_cost' => round($newAverage, 4), 'updated_at' => now()];
                if (isset($line['selling_price'])) $productUpdate['retail_price'] = round((float) $line['selling_price'], 4);
                DB::table('products')->where('id', $product->id)->update($productUpdate);
                if (isset($line['selling_price']) && (float) $unit->conversion_factor === 1.0) {
                    DB::table('product_units')->where('id', $unit->id)->update(['selling_price' => round((float) $line['selling_price'], 4), 'updated_at' => now()]);
                }
                $lineTotal = round($accepted * (float) $line['unit_cost'], 2); $total += $lineTotal;
                DB::table('goods_receipt_lines')->insert([
                    'goods_receipt_id' => $receiptId, 'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                    'product_id' => $product->id, 'unit_id' => $unit->unit_id, 'product_batch_id' => $batchId,
                    'conversion_factor' => $unit->conversion_factor, 'quantity' => $qty, 'free_quantity' => $free,
                    'accepted_quantity' => $accepted, 'rejected_quantity' => $line['rejected_quantity'] ?? 0,
                    'unit_cost' => $line['unit_cost'], 'selling_price' => $line['selling_price'] ?? null, 'line_total' => $lineTotal,
                    'batch_no' => $line['batch_no'] ?? null, 'manufactured_date' => $line['manufactured_date'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->moveStock($user, $store->id, $product->id, $batchId, 'goods_receipt', $receiptNo, $baseQty, 0, $unitCost);
            }
            DB::table('goods_receipts')->where('id', $receiptId)->update(['grand_total' => round($total, 2), 'updated_at' => now()]);
            if ($data['credit_purchase'] ?? true) DB::table('supplier_account_entries')->insert([
                'branch_code' => $user->BC, 'supplier_id' => $supplier->id, 'reference_type' => 'goods_receipt',
                'reference_no' => $receiptNo, 'entry_date' => $data['supplier_invoice_date'], 'debit' => 0, 'credit' => round($total, 2),
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($data['purchase_order_id'] ?? null) $this->updatePurchaseOrderStatus((int) $data['purchase_order_id']);
            $this->audit($user, 'post', 'goods_receipt', $receiptId, null, null, ['total' => $total]);
            $receipt = DB::table('goods_receipts')->find($receiptId);
            $receipt->lines = DB::table('goods_receipt_lines')->where('goods_receipt_id', $receiptId)->get();
            return $receipt;
        });
    }

    private function updatePurchaseOrderStatus(int $orderId): void
    {
        $lines = DB::table('grocery_purchase_order_lines')->where('purchase_order_id', $orderId)->get();
        foreach ($lines as $line) {
            $received = DB::table('goods_receipt_lines')->where('purchase_order_line_id', $line->id)->sum('accepted_quantity');
            DB::table('grocery_purchase_order_lines')->where('id', $line->id)->update(['received_quantity' => $received, 'updated_at' => now()]);
        }
        $remaining = DB::table('grocery_purchase_order_lines')->where('purchase_order_id', $orderId)->whereColumn('received_quantity', '<', 'ordered_quantity')->count();
        $receivedAny = DB::table('grocery_purchase_order_lines')->where('purchase_order_id', $orderId)->where('received_quantity', '>', 0)->exists();
        DB::table('grocery_purchase_orders')->where('id', $orderId)->update(['status' => $remaining === 0 ? 'received' : ($receivedAny ? 'partially_received' : 'approved'), 'updated_at' => now()]);
    }

    public function adjustStock(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            $store = DB::table('stores')->where('id', $data['store_id'])->where('BC', $user->BC)->first();
            if (! $store) throw ValidationException::withMessages(['store_id' => 'Store is outside your branch.']);
            $number = $this->nextNumber($user->BC, 'adjustment');
            $id = DB::table('stock_adjustments')->insertGetId([
                'adjustment_no' => $number, 'branch_code' => $user->BC, 'store_id' => $store->id,
                'reason' => $data['reason'], 'notes' => $data['notes'] ?? null, 'created_by' => $user->id,
                'approved_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['lines'] as $line) {
                $product = DB::table('products')->where('id', $line['product_id'])->where('branch_code', $user->BC)->first();
                if (! $product) throw ValidationException::withMessages(['product_id' => 'Product is outside your branch.']);
                $delta = round((float) $line['quantity_delta'], 6);
                if ($delta < 0 && $this->stockQuantity($product->id, $store->id) + $delta < -0.000001) throw ValidationException::withMessages(['quantity_delta' => "Adjustment would make {$product->name} negative."]);
                DB::table('stock_adjustment_lines')->insert([
                    'stock_adjustment_id' => $id, 'product_id' => $product->id, 'product_batch_id' => $line['product_batch_id'] ?? null,
                    'quantity_delta' => $delta, 'unit_cost' => $product->average_cost, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->moveStock($user, $store->id, $product->id, $line['product_batch_id'] ?? null, 'adjustment', $number, max(0, $delta), max(0, -$delta), $product->average_cost);
            }
            $this->audit($user, 'post', 'stock_adjustment', $id, $data['reason'], null, $data);
            return DB::table('stock_adjustments')->find($id);
        });
    }

    public function transferStock(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            if ((int) $data['from_store_id'] === (int) $data['to_store_id']) {
                throw ValidationException::withMessages(['to_store_id' => 'Destination must be different from the source store.']);
            }
            foreach (['from_store_id', 'to_store_id'] as $field) {
                if (! DB::table('stores')->where('id', $data[$field])->where('BC', $user->BC)->exists()) {
                    throw ValidationException::withMessages([$field => 'Store is outside your branch.']);
                }
            }
            $number = $this->nextNumber($user->BC, 'transfer');
            $id = DB::table('grocery_stock_transfers')->insertGetId([
                'transfer_no' => $number, 'branch_code' => $user->BC, 'from_store_id' => $data['from_store_id'],
                'to_store_id' => $data['to_store_id'], 'status' => 'dispatched', 'notes' => $data['notes'] ?? null,
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['lines'] as $line) {
                $product = DB::table('products')->where('id', $line['product_id'])->where('branch_code', $user->BC)->first();
                if (! $product) throw ValidationException::withMessages(['product_id' => 'Product is outside your branch.']);
                $quantity = round((float) $line['quantity'], 6);
                if ($quantity <= 0 || $this->stockQuantity($product->id, $data['from_store_id']) + 0.000001 < $quantity) {
                    throw ValidationException::withMessages(['quantity' => "Insufficient source stock for {$product->name}."]);
                }
                $batchId = $line['product_batch_id'] ?? null;
                if ($product->batch_tracked && ! $batchId) throw ValidationException::withMessages(['product_batch_id' => "Select a batch for {$product->name}."]);
                DB::table('grocery_stock_transfer_lines')->insert([
                    'stock_transfer_id' => $id, 'product_id' => $product->id, 'product_batch_id' => $batchId,
                    'quantity' => $quantity, 'received_quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->moveStock($user, $data['from_store_id'], $product->id, $batchId, 'transfer_out', $number, 0, $quantity, $product->average_cost);
            }
            $this->audit($user, 'dispatch', 'stock_transfer', $id, null, null, $data);
            return DB::table('grocery_stock_transfers')->find($id);
        });
    }

    public function receiveTransfer(User $user, int $id): object
    {
        return DB::transaction(function () use ($user, $id) {
            $transfer = DB::table('grocery_stock_transfers')->where('id', $id)->where('branch_code', $user->BC)->lockForUpdate()->first();
            if (! $transfer || $transfer->status !== 'dispatched') throw ValidationException::withMessages(['transfer' => 'Only dispatched transfers can be received.']);
            $lines = DB::table('grocery_stock_transfer_lines')->where('stock_transfer_id', $id)->get();
            foreach ($lines as $line) {
                $product = DB::table('products')->find($line->product_id); $destinationBatch = null;
                if ($line->product_batch_id) {
                    $sourceBatch = DB::table('product_batches')->find($line->product_batch_id);
                    $destinationBatch = DB::table('product_batches')->where('branch_code', $user->BC)->where('store_id', $transfer->to_store_id)
                        ->where('product_id', $product->id)->where('batch_no', $sourceBatch->batch_no)->first();
                    if (! $destinationBatch) {
                        $destinationId = DB::table('product_batches')->insertGetId([
                            'branch_code' => $user->BC, 'store_id' => $transfer->to_store_id, 'product_id' => $product->id,
                            'batch_no' => $sourceBatch->batch_no, 'manufactured_date' => $sourceBatch->manufactured_date,
                            'expiry_date' => $sourceBatch->expiry_date, 'quantity' => 0, 'unit_cost' => $sourceBatch->unit_cost,
                            'selling_price' => $sourceBatch->selling_price, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $destinationBatch = DB::table('product_batches')->find($destinationId);
                    }
                }
                $this->moveStock($user, $transfer->to_store_id, $product->id, $destinationBatch?->id, 'transfer_in', $transfer->transfer_no, $line->quantity, 0, $product->average_cost);
                DB::table('grocery_stock_transfer_lines')->where('id', $line->id)->update(['received_quantity' => $line->quantity, 'updated_at' => now()]);
            }
            DB::table('grocery_stock_transfers')->where('id', $id)->update(['status' => 'received', 'received_by' => $user->id, 'updated_at' => now()]);
            $this->audit($user, 'receive', 'stock_transfer', $id);
            return DB::table('grocery_stock_transfers')->find($id);
        });
    }

    public function createStockCount(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            if (! DB::table('stores')->where('id', $data['store_id'])->where('BC', $user->BC)->exists()) throw ValidationException::withMessages(['store_id' => 'Store is outside your branch.']);
            $id = DB::table('stock_counts')->insertGetId([
                'count_no' => $this->nextNumber($user->BC, 'stock_count'), 'branch_code' => $user->BC,
                'store_id' => $data['store_id'], 'type' => $data['type'], 'status' => 'counting',
                'snapshot_at' => now(), 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $productIds = $data['product_ids'] ?? DB::table('products')->where('branch_code', $user->BC)->pluck('id')->all();
            foreach ($productIds as $productId) {
                $batches = DB::table('product_batches')->where('product_id', $productId)->where('store_id', $data['store_id'])->where('quantity', '>', 0)->get();
                if ($batches->isNotEmpty()) {
                    foreach ($batches as $batch) DB::table('stock_count_lines')->insert([
                        'stock_count_id' => $id, 'product_id' => $productId, 'product_batch_id' => $batch->id,
                        'system_quantity' => $batch->quantity, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    DB::table('stock_count_lines')->insert([
                        'stock_count_id' => $id, 'product_id' => $productId, 'product_batch_id' => null,
                        'system_quantity' => $this->stockQuantity($productId, $data['store_id']), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $this->audit($user, 'create', 'stock_count', $id);
            return DB::table('stock_counts')->find($id);
        });
    }

    public function postStockCount(User $user, int $id, array $data): object
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $count = DB::table('stock_counts')->where('id', $id)->where('branch_code', $user->BC)->lockForUpdate()->first();
            if (! $count || ! in_array($count->status, ['counting', 'review'], true)) throw ValidationException::withMessages(['count' => 'Stock count cannot be posted.']);
            foreach ($data['lines'] as $input) {
                $line = DB::table('stock_count_lines')->where('id', $input['line_id'])->where('stock_count_id', $id)->first();
                if (! $line) throw ValidationException::withMessages(['line_id' => 'Count line is invalid.']);
                $counted = round((float) $input['counted_quantity'], 6); $variance = round($counted - (float) $line->system_quantity, 6);
                DB::table('stock_count_lines')->where('id', $line->id)->update(['counted_quantity' => $counted, 'variance' => $variance, 'updated_at' => now()]);
                if ($variance !== 0.0) {
                    $product = DB::table('products')->find($line->product_id);
                    if ($variance < 0 && $this->stockQuantity($product->id, $count->store_id) + $variance < -0.000001) throw ValidationException::withMessages(['counted_quantity' => "Variance would make {$product->name} negative."]);
                    $this->moveStock($user, $count->store_id, $product->id, $line->product_batch_id, 'stock_count', $count->count_no, max(0, $variance), max(0, -$variance), $product->average_cost);
                }
            }
            DB::table('stock_counts')->where('id', $id)->update(['status' => 'posted', 'posted_by' => $user->id, 'updated_at' => now()]);
            $this->audit($user, 'post', 'stock_count', $id, $data['reason'] ?? 'Count variance approved');
            return DB::table('stock_counts')->find($id);
        });
    }

    public function purchaseReturn(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            $supplier = DB::table('suppliers')->where('id', $data['supplier_id'])->where('BC', $user->BC)->first();
            if (! $supplier) throw ValidationException::withMessages(['supplier_id' => 'Supplier is outside your branch.']);
            $number = $this->nextNumber($user->BC, 'purchase_return'); $total = 0; $prepared = [];
            foreach ($data['lines'] as $input) {
                $receiptLine = DB::table('goods_receipt_lines')->where('id', $input['goods_receipt_line_id'])->first();
                if (! $receiptLine) throw ValidationException::withMessages(['goods_receipt_line_id' => 'Receipt line is invalid.']);
                $returned = (float) DB::table('purchase_return_lines')->where('goods_receipt_line_id', $receiptLine->id)->sum('quantity');
                $qty = round((float) $input['quantity'], 6);
                if ($qty <= 0 || $returned + $qty > (float) $receiptLine->accepted_quantity + 0.000001) throw ValidationException::withMessages(['quantity' => 'Return quantity exceeds the received quantity.']);
                $baseQty = $qty * (float) $receiptLine->conversion_factor;
                if ($this->stockQuantity($receiptLine->product_id, $data['store_id']) + 0.000001 < $baseQty) throw ValidationException::withMessages(['quantity' => 'Returned stock is no longer available.']);
                $lineTotal = round($qty * (float) $receiptLine->unit_cost, 2); $total += $lineTotal;
                $prepared[] = [$receiptLine, $qty, $baseQty, $lineTotal];
            }
            $returnId = DB::table('purchase_returns')->insertGetId([
                'return_no' => $number, 'branch_code' => $user->BC, 'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'supplier_id' => $supplier->id, 'store_id' => $data['store_id'], 'status' => 'posted', 'total' => round($total, 2),
                'reason' => $data['reason'], 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($prepared as [$line, $qty, $baseQty, $lineTotal]) {
                DB::table('purchase_return_lines')->insert([
                    'purchase_return_id' => $returnId, 'goods_receipt_line_id' => $line->id, 'product_id' => $line->product_id,
                    'product_batch_id' => $line->product_batch_id, 'quantity' => $qty, 'unit_cost' => $line->unit_cost,
                    'line_total' => $lineTotal, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->moveStock($user, $data['store_id'], $line->product_id, $line->product_batch_id, 'purchase_return', $number, 0, $baseQty, $line->unit_cost / $line->conversion_factor);
            }
            DB::table('supplier_account_entries')->insert([
                'branch_code' => $user->BC, 'supplier_id' => $supplier->id, 'reference_type' => 'purchase_return',
                'reference_no' => $number, 'entry_date' => today(), 'debit' => round($total, 2), 'credit' => 0,
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($user, 'post', 'purchase_return', $returnId, $data['reason']);
            return DB::table('purchase_returns')->find($returnId);
        });
    }

    public function createReturn(User $user, array $data): object
    {
        return DB::transaction(function () use ($user, $data) {
            $sale = DB::table('sales')->where('id', $data['sale_id'])->where('branch_code', $user->BC)->lockForUpdate()->first();
            if (! $sale || $sale->status === 'voided') throw ValidationException::withMessages(['sale_id' => 'Sale is not eligible for return.']);
            $returnNo = $this->nextNumber($user->BC, 'return'); $total = 0; $prepared = [];
            foreach ($data['lines'] as $line) {
                $saleLine = DB::table('sale_lines')->where('id', $line['sale_line_id'])->where('sale_id', $sale->id)->first();
                if (! $saleLine) throw ValidationException::withMessages(['lines' => 'Return line does not belong to this sale.']);
                $returned = (float) DB::table('sales_return_lines')->where('sale_line_id', $saleLine->id)->sum('quantity');
                $qty = (float) $line['quantity'];
                if ($qty <= 0 || $returned + $qty > (float) $saleLine->quantity + 0.000001) throw ValidationException::withMessages(['quantity' => 'Return quantity exceeds the eligible sold quantity.']);
                $ratio = $qty / (float) $saleLine->quantity; $refund = round((float) $saleLine->line_total * $ratio, 2);
                $prepared[] = [$saleLine, $qty, $refund, $line['condition'] ?? 'saleable']; $total += $refund;
            }
            $returnId = DB::table('sales_returns')->insertGetId([
                'return_no' => $returnNo, 'branch_code' => $user->BC, 'sale_id' => $sale->id, 'store_id' => $data['store_id'],
                'refund_total' => round($total, 2), 'refund_method' => $data['refund_method'], 'reason' => $data['reason'],
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($prepared as [$line, $qty, $refund, $condition]) {
                $baseQty = round($qty * (float) $line->conversion_factor, 6);
                DB::table('sales_return_lines')->insert([
                    'sales_return_id' => $returnId, 'sale_line_id' => $line->id, 'quantity' => $qty,
                    'base_quantity' => $baseQty, 'refund_amount' => $refund, 'condition' => $condition,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($condition === 'saleable') $this->moveStock($user, $data['store_id'], $line->product_id, $line->product_batch_id, 'sales_return', $returnNo, $baseQty, 0, $line->unit_cost);
            }
            $soldQty = (float) DB::table('sale_lines')->where('sale_id', $sale->id)->sum('quantity');
            $returnedQty = (float) DB::table('sales_return_lines as rl')->join('sales_returns as r', 'r.id', '=', 'rl.sales_return_id')->where('r.sale_id', $sale->id)->sum('rl.quantity');
            DB::table('sales')->where('id', $sale->id)->update(['status' => $returnedQty >= $soldQty ? 'returned' : 'partially_returned', 'updated_at' => now()]);
            $this->audit($user, 'return', 'sale', $sale->id, $data['reason'], null, ['return_id' => $returnId, 'total' => $total]);
            return DB::table('sales_returns')->find($returnId);
        });
    }

    public function dashboard(User $user, ?string $from = null, ?string $to = null): array
    {
        $from ??= today()->toDateString(); $to ??= today()->toDateString();
        $sales = DB::table('sales')->where('branch_code', $user->BC)->where('status', '!=', 'held')->where('status', '!=', 'voided')->whereBetween(DB::raw('date(sold_at)'), [$from, $to]);
        $summary = (clone $sales)->selectRaw('COALESCE(SUM(grand_total),0) sales, COALESCE(SUM(cost_total),0) cost, COUNT(*) transactions, COALESCE(AVG(grand_total),0) average_basket')->first();
        $inventory = $this->inventory($user);
        return [
            'sales' => round((float) $summary->sales, 2), 'gross_profit' => round((float) $summary->sales - (float) $summary->cost, 2),
            'transactions' => (int) $summary->transactions, 'average_basket' => round((float) $summary->average_basket, 2),
            'low_stock_count' => collect($inventory)->where('low_stock', true)->count(),
            'near_expiry_count' => collect($inventory)->sum('near_expiry'),
            'open_shifts' => DB::table('cashier_shifts')->where('branch_code', $user->BC)->where('status', 'open')->count(),
            'recent_sales' => (clone $sales)->orderByDesc('sold_at')->limit(8)->get(),
            'payment_methods' => DB::table('sale_payments as sp')->join('sales as s', 's.id', '=', 'sp.sale_id')
                ->where('s.branch_code', $user->BC)->whereBetween(DB::raw('date(s.sold_at)'), [$from, $to])->groupBy('sp.method')->selectRaw('sp.method, SUM(sp.amount) total')->get(),
        ];
    }
}
