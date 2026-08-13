export interface GroceryUnit {
  id?: number;
  unit_id: number;
  code: string;
  name: string;
  conversion_factor: number;
  selling_price: number | null;
  purchase_cost?: number | null;
  decimal_places: number;
}

export interface GroceryBatch {
  id: number;
  batch_no: string;
  expiry_date: string | null;
  quantity: number;
  selling_price: number | null;
}

export interface GroceryProduct {
  id: number;
  sku: string;
  name: string;
  local_name?: string | null;
  retail_price: number;
  latest_cost: number;
  wholesale_price?: number | null;
  average_cost: number;
  stock: number;
  base_unit_id: number;
  base_unit_code: string;
  weighted: boolean;
  allow_decimal_qty: boolean;
  batch_tracked: boolean;
  expiry_tracked: boolean;
  tax_rate?: number | null;
  tax_inclusive?: boolean | number | null;
  category_id?: number | null;
  category_name?: string | null;
  brand_id?: number | null;
  brand_name?: string | null;
  tax_rate_id?: number | null;
  tax_name?: string | null;
  preferred_supplier_id?: number | null;
  preferred_supplier_name?: string | null;
  reorder_level?: number;
  shelf_location?: string | null;
  active?: boolean;
  sold_quantity?: number;
  sold_count?: number;
  barcodes: string[];
  units: GroceryUnit[];
  batches: GroceryBatch[];
}

export interface GroceryOptions {
  stores: Array<{ id: number; name: string; location?: string }>;
  registers: Array<{ id: number; name: string; code: string; store_id: number }>;
  units: Array<{ id: number; code: string; name: string; decimal_places: number }>;
  tax_rates: Array<{ id: number; name: string; rate: number; inclusive: boolean }>;
  categories: Array<{ id: number; name: string }>;
  brands: Array<{ id: number; name: string }>;
  suppliers: Array<{ id: number; name: string; Code: string }>;
  customers: Array<{ id: number; name: string; Code: string }>;
  expense_categories: Array<{ id: number; name: string }>;
  promotions: Array<{ id: number; type: string; target_type: string; target_id: number | null; value: number; minimum_qty: number | null; minimum_subtotal: number | null; buy_qty: number | null; get_qty: number | null; starts_at: string; ends_at: string; active: boolean }>;
  company: Record<string, string | number | boolean | null> | null;
  open_shift: { id: number; shift_no: string; register_id: number; opening_float: number } | null;
  settings: Record<string, string>;
}

export interface PosLine {
  key: string;
  product: GroceryProduct;
  unit: GroceryUnit;
  quantity: number;
  unitPrice: number;
  discount: number;
  discountType?: 'amount' | 'percent';
  discountValue?: number;
}

export interface DashboardSummary {
  sales: number;
  gross_profit: number;
  transactions: number;
  average_basket: number;
  low_stock_count: number;
  near_expiry_count: number;
  open_shifts: number;
  recent_sales: Array<Record<string, unknown>>;
  payment_methods: Array<{ method: string; total: number }>;
}

export const money = (value: number | string | null | undefined, currency = 'LKR') =>
  new Intl.NumberFormat('en-LK', { style: 'currency', currency, minimumFractionDigits: 2 }).format(Number(value || 0));

export const quantity = (value: number | string | null | undefined) =>
  new Intl.NumberFormat('en-LK', { maximumFractionDigits: 3 }).format(Number(value || 0));
