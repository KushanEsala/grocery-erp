<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $balances = DB::table('inventory_movements as movement')
            ->join('products as product', 'product.id', '=', 'movement.product_id')
            ->where(fn ($query) => $query->where('product.batch_tracked', true)->orWhere('product.expiry_tracked', true))
            ->groupBy('movement.branch_code', 'movement.store_id', 'movement.product_id', 'product.average_cost', 'product.retail_price')
            ->selectRaw('movement.branch_code, movement.store_id, movement.product_id, product.average_cost, product.retail_price, SUM(movement.quantity_in - movement.quantity_out) as ledger_quantity')
            ->get();

        foreach ($balances as $balance) {
            $batchQuantity = (float) DB::table('product_batches')
                ->where('branch_code', $balance->branch_code)->where('store_id', $balance->store_id)
                ->where('product_id', $balance->product_id)->sum('quantity');
            $missingQuantity = round((float) $balance->ledger_quantity - $batchQuantity, 6);
            if ($missingQuantity <= 0.000001) continue;

            $existing = DB::table('product_batches')->where('branch_code', $balance->branch_code)
                ->where('store_id', $balance->store_id)->where('product_id', $balance->product_id)
                ->where('batch_no', 'SYSTEM-OPENING')->first();
            if ($existing) {
                DB::table('product_batches')->where('id', $existing->id)->update([
                    'quantity' => round((float) $existing->quantity + $missingQuantity, 6), 'updated_at' => now(),
                ]);
            } else {
                DB::table('product_batches')->insert([
                    'branch_code' => $balance->branch_code, 'store_id' => $balance->store_id,
                    'product_id' => $balance->product_id, 'batch_no' => 'SYSTEM-OPENING',
                    'manufactured_date' => null, 'expiry_date' => null, 'quantity' => $missingQuantity,
                    'unit_cost' => $balance->average_cost, 'selling_price' => $balance->retail_price,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data repair is intentionally irreversible: repaired batches may be referenced by later sales.
    }
};
