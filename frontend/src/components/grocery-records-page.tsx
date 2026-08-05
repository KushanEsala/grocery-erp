'use client';

import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { Boxes, CalendarClock, ClipboardList, Download, Package, Percent, Plus, Printer, ReceiptText, RefreshCcw, Scale, ShieldCheck, Store, Truck } from 'lucide-react';
import { api, getApiErrorMessage } from '@/lib/api';
import { GroceryOptions, GroceryProduct, money, quantity } from '@/lib/grocery';
import { OperationField, OperationHeader, OperationModal, OperationNotice } from '@/components/operation-ui';

type Module = 'products' | 'units' | 'registers' | 'promotions' | 'inventory' | 'expiry' | 'reorder' | 'sales' | 'purchase-orders' | 'goods-receipts' | 'transfers' | 'adjustments' | 'stock-counts' | 'shifts' | 'expenses' | 'supplier-payments' | 'audit' | 'reports' | 'purchase-returns' | 'sales-returns' | 'cash';
type Row = Record<string, unknown>;

const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100';

const CONFIG: Record<Module, { eyebrow: string; title: string; description: string; endpoint: string; icon: typeof Package; create?: string }> = {
  products: { eyebrow: 'Catalogue', title: 'Products', description: 'Barcodes, selling units, price, tax, and grocery stock rules.', endpoint: '/v1/grocery/products', icon: Package, create: 'Add product' },
  units: { eyebrow: 'Catalogue setup', title: 'Units of measure', description: 'Base and alternate units used for purchase and sale conversions.', endpoint: '/v1/grocery/masters/units', icon: Scale, create: 'Add unit' },
  registers: { eyebrow: 'Counter setup', title: 'Registers', description: 'POS terminals and their stock locations.', endpoint: '/v1/grocery/masters/registers', icon: Store, create: 'Add register' },
  promotions: { eyebrow: 'Pricing', title: 'Promotions', description: 'Scheduled product, category, brand, and basket offers.', endpoint: '/v1/grocery/masters/promotions', icon: Percent, create: 'Add promotion' },
  inventory: { eyebrow: 'Stock control', title: 'Stock levels', description: 'Current quantity, value, reorder status, and expiry attention.', endpoint: '/v1/grocery/inventory', icon: Boxes },
  expiry: { eyebrow: 'Stock control', title: 'Batch and expiry', description: 'FEFO batches ordered by their expiry date.', endpoint: '/v1/grocery/reports/expiry', icon: CalendarClock },
  reorder: { eyebrow: 'Stock control', title: 'Reorder alerts', description: 'Products at or below their configured reorder level.', endpoint: '/v1/grocery/inventory', icon: RefreshCcw },
  sales: { eyebrow: 'Sales', title: 'Sales history', description: 'Completed, held, returned, and voided baskets.', endpoint: '/v1/grocery/sales', icon: ReceiptText },
  'purchase-orders': { eyebrow: 'Purchasing', title: 'Purchase orders', description: 'Draft and approved supplier orders with receipt progress.', endpoint: '/v1/grocery/purchase-orders', icon: ClipboardList, create: 'New order' },
  'goods-receipts': { eyebrow: 'Purchasing', title: 'Goods receipts', description: 'Receive supplier stock with cost, batch, and expiry.', endpoint: '/v1/grocery/goods-receipts', icon: Truck, create: 'Receive goods' },
  transfers: { eyebrow: 'Stock control', title: 'Stock transfers', description: 'Dispatch and receive stock between branch locations.', endpoint: '/v1/grocery/transfers', icon: RefreshCcw, create: 'New transfer' },
  adjustments: { eyebrow: 'Stock control', title: 'Stock adjustments', description: 'Authorized opening, damage, expiry, spoilage, and correction entries.', endpoint: '/v1/grocery/inventory', icon: RefreshCcw, create: 'Adjust stock' },
  'stock-counts': { eyebrow: 'Stock control', title: 'Stock counts', description: 'Full and cycle count snapshots with variance posting.', endpoint: '/v1/grocery/stock-counts', icon: ClipboardList, create: 'Start count' },
  shifts: { eyebrow: 'Cash control', title: 'Cashier shifts', description: 'Opening floats, expected cash, counted cash, and variances.', endpoint: '/v1/grocery/shifts', icon: CalendarClock, create: 'Open shift' },
  expenses: { eyebrow: 'Cash control', title: 'Expenses', description: 'Posted branch expenses by category and payment method.', endpoint: '/v1/grocery/expenses', icon: ReceiptText, create: 'Record expense' },
  'supplier-payments': { eyebrow: 'Supplier accounts', title: 'Supplier payments', description: 'Post supplier payments and update the payable balance.', endpoint: '/v1/grocery/reports/suppliers', icon: Truck, create: 'Pay supplier' },
  audit: { eyebrow: 'Administration', title: 'Audit log', description: 'Sensitive stock, sale, return, shift, price, and payment actions.', endpoint: '/v1/grocery/audit', icon: ShieldCheck },
  reports: { eyebrow: 'Management', title: 'Reports', description: 'Sales, profit, stock, expiry, supplier, shift, expense, and audit views.', endpoint: '/v1/grocery/reports/sales', icon: ClipboardList },
  'purchase-returns': { eyebrow: 'Purchasing', title: 'Purchase returns', description: 'Return eligible received stock and debit the supplier balance.', endpoint: '/v1/grocery/goods-receipts', icon: RefreshCcw, create: 'Post return' },
  'sales-returns': { eyebrow: 'Sales', title: 'Sales returns', description: 'Refund eligible quantities from an original sale.', endpoint: '/v1/grocery/sales', icon: RefreshCcw, create: 'Post return' },
  cash: { eyebrow: 'Cash control', title: 'Cash movements', description: 'Cash in, cash out, and safe drops for an open shift.', endpoint: '/v1/grocery/shifts', icon: ReceiptText, create: 'Record movement' },
};

function rowsFromPayload(payload: unknown): Row[] {
  if (Array.isArray(payload)) return payload as Row[];
  if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) return (payload as { data: Row[] }).data;
  return [];
}

export function GroceryRecordsPage({ module }: { module: Module }) {
  const config = CONFIG[module];
  const [rows, setRows] = useState<Row[]>([]);
  const [options, setOptions] = useState<GroceryOptions | null>(null);
  const [products, setProducts] = useState<GroceryProduct[]>([]);
  const [search, setSearch] = useState('');
  const [report, setReport] = useState('sales');
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [form, setForm] = useState<Record<string, string>>({});
  const [lines, setLines] = useState<Array<Record<string, string>>>([]);

  const endpoint = module === 'reports' ? `/v1/grocery/reports/${report}` : config.endpoint;
  const load = useCallback(async () => {
    try {
      const [response, optionResponse, productResponse] = await Promise.all([
        api.get<unknown>(endpoint + (search ? `${endpoint.includes('?') ? '&' : '?'}search=${encodeURIComponent(search)}` : '')),
        api.get<GroceryOptions>('/v1/grocery/options'),
        api.get<GroceryProduct[]>('/v1/grocery/products'),
      ]);
      let nextRows = rowsFromPayload(response.data);
      if (module === 'reorder') nextRows = nextRows.filter((row) => Boolean(row.low_stock));
      setRows(nextRows); setOptions(optionResponse.data || null); setProducts(productResponse.data || []);
    } catch (error) { setNotice({ type: 'error', text: getApiErrorMessage(error, 'Could not load this workspace.') }); }
  }, [endpoint, module, search]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const columns = useMemo(() => {
    const hidden = new Set(['id', 'created_by', 'updated_at', 'before_values', 'after_values', 'voided_by', 'approved_by']);
    return Array.from(new Set(rows.flatMap((row) => Object.keys(row)))).filter((key) => !hidden.has(key)).slice(0, 8);
  }, [rows]);

  function value(key: string, row: Row) {
    const raw = row[key];
    if (raw === null || raw === undefined) return '—';
    if (['grand_total', 'total', 'sales', 'profit', 'cost', 'balance', 'amount', 'stock_value', 'refund_total', 'variance'].some((name) => key.includes(name))) return money(Number(raw));
    if (key.includes('quantity') || key === 'stock') return quantity(Number(raw));
    if (typeof raw === 'boolean') return raw ? 'Yes' : 'No';
    if (typeof raw === 'object') return JSON.stringify(raw);
    return String(raw).replaceAll('_', ' ');
  }

  function set(name: string, next: string) { setForm((current) => ({ ...current, [name]: next })); }
  function addLine() { setLines((current) => [...current, { product_id: String(products[0]?.id || ''), unit_id: String(products[0]?.units[0]?.unit_id || ''), quantity: '1', unit_cost: String(products[0]?.average_cost || 0) }]); }

  function exportCsv() {
    const keys = columns;
    const quote = (entry: unknown) => `"${String(entry ?? '').replaceAll('"', '""')}"`;
    const csv = [keys.map(quote).join(','), ...rows.map((row) => keys.map((key) => quote(row[key])).join(','))].join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const anchor = document.createElement('a'); anchor.href = url; anchor.download = `${module}-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url);
  }

  async function rowAction(row: Row, action: 'approve' | 'receive' | 'close' | 'void' | 'count') {
    const id = Number(row.id);
    let path = ''; let body: Record<string, unknown> = {};
    if (action === 'approve') path = `/v1/grocery/purchase-orders/${id}/approve`;
    if (action === 'receive') path = `/v1/grocery/transfers/${id}/receive`;
    if (action === 'close') {
      const counted = window.prompt('Enter the counted cash for this shift:');
      if (counted === null) return;
      path = `/v1/grocery/shifts/${id}/close`; body = { counted_cash: Number(counted) };
    }
    if (action === 'void') {
      const reason = window.prompt('Reason for voiding this completed sale:');
      if (!reason) return;
      path = `/v1/grocery/sales/${id}/void`; body = { reason };
    }
    if (action === 'count') {
      try {
        const response = await api.get<{ lines: Array<{ id: number; sku: string; product_name: string; batch_no?: string | null; system_quantity: number }> }>(`/v1/grocery/stock-counts/${id}`);
        const countLines = response.data?.lines || []; const entered: Array<{ line_id: number; counted_quantity: number }> = [];
        for (const line of countLines) {
          const counted = window.prompt(`${line.sku} — ${line.product_name}${line.batch_no ? ` (${line.batch_no})` : ''}\nSystem quantity: ${line.system_quantity}\nEnter physical quantity:`, String(line.system_quantity));
          if (counted === null) return;
          entered.push({ line_id: line.id, counted_quantity: Number(counted) });
        }
        path = `/v1/grocery/stock-counts/${id}/post`; body = { reason: 'Physical count approved', lines: entered };
      } catch (error) { setNotice({ type: 'error', text: getApiErrorMessage(error, 'Could not load count lines.') }); return; }
    }
    setSaving(true); setNotice(null);
    try { await api.post(path, body); setNotice({ type: 'success', text: 'Workflow action completed.' }); await load(); }
    catch (error) { setNotice({ type: 'error', text: getApiErrorMessage(error, 'Could not complete the action.') }); }
    finally { setSaving(false); }
  }

  async function submit(event: FormEvent) {
    event.preventDefault(); setSaving(true); setNotice(null);
    try {
      const numeric = (name: string, fallback = 0) => Number(form[name] || fallback);
      let path = config.endpoint; let body: Record<string, unknown> = {};
      if (module === 'products') {
        body = { sku: form.sku, name: form.name, base_unit_id: numeric('base_unit_id', options?.units[0]?.id), retail_price: numeric('retail_price'), latest_cost: numeric('latest_cost'), reorder_level: numeric('reorder_level'), batch_tracked: form.batch_tracked === 'true', expiry_tracked: form.expiry_tracked === 'true', weighted: form.weighted === 'true', allow_decimal_qty: form.weighted === 'true', barcodes: form.barcode ? [form.barcode] : [] };
      } else if (module === 'units') body = { code: form.code, name: form.name, decimal_places: numeric('decimal_places'), active: true };
      else if (module === 'registers') body = { code: form.code, name: form.name, store_id: numeric('store_id', options?.stores[0]?.id), active: true };
      else if (module === 'promotions') body = { name: form.name, type: form.type || 'percentage', target_type: form.target_type || 'product', target_id: form.target_type === 'basket' ? null : numeric('target_id', products[0]?.id), value: numeric('value'), minimum_qty: numeric('minimum_qty') || null, priority: 100, stackable: false, starts_at: form.starts_at, ends_at: form.ends_at, active: true };
      else if (module === 'shifts') { path = '/v1/grocery/shifts/open'; body = { register_id: numeric('register_id', options?.registers[0]?.id), opening_float: numeric('opening_float') }; }
      else if (module === 'expenses') { path = '/v1/grocery/expenses'; body = { category_id: numeric('category_id', options?.expense_categories[0]?.id), expense_date: form.expense_date, payee: form.payee, amount: numeric('amount'), payment_method: form.payment_method || 'cash', reference: form.reference }; }
      else if (module === 'supplier-payments') { path = '/v1/grocery/supplier-payments'; body = { supplier_id: numeric('supplier_id', options?.suppliers[0]?.id), payment_date: form.payment_date, amount: numeric('amount'), method: form.method || 'cash', reference: form.reference }; }
      else if (module === 'cash') { path = '/v1/grocery/cash-movements'; body = { shift_id: options?.open_shift?.id, type: form.type || 'cash_out', amount: numeric('amount'), reason: form.reason, reference: form.reference }; }
      else if (module === 'adjustments') { path = '/v1/grocery/stock-adjustments'; body = { store_id: numeric('store_id', options?.stores[0]?.id), reason: form.reason || 'correction', notes: form.notes, lines: lines.map((line) => ({ product_id: Number(line.product_id), product_batch_id: line.product_batch_id ? Number(line.product_batch_id) : null, quantity_delta: Number(line.quantity) })) }; }
      else if (module === 'transfers') { path = '/v1/grocery/transfers'; body = { from_store_id: numeric('from_store_id', options?.stores[0]?.id), to_store_id: numeric('to_store_id', options?.stores[1]?.id), notes: form.notes, lines: lines.map((line) => ({ product_id: Number(line.product_id), product_batch_id: line.product_batch_id ? Number(line.product_batch_id) : null, quantity: Number(line.quantity) })) }; }
      else if (module === 'stock-counts') { path = '/v1/grocery/stock-counts'; body = { store_id: numeric('store_id', options?.stores[0]?.id), type: form.type || 'cycle', product_ids: lines.map((line) => Number(line.product_id)) }; }
      else if (module === 'purchase-orders') { path = '/v1/grocery/purchase-orders'; body = { supplier_id: numeric('supplier_id', options?.suppliers[0]?.id), store_id: numeric('store_id', options?.stores[0]?.id), order_date: form.order_date, expected_date: form.expected_date || null, notes: form.notes, lines: lines.map((line) => ({ product_id: Number(line.product_id), unit_id: Number(line.unit_id), quantity: Number(line.quantity), unit_cost: Number(line.unit_cost), free_quantity: Number(line.free_quantity || 0) })) }; }
      else if (module === 'goods-receipts') { path = '/v1/grocery/goods-receipts'; body = { supplier_id: numeric('supplier_id', options?.suppliers[0]?.id), store_id: numeric('store_id', options?.stores[0]?.id), supplier_invoice_no: form.supplier_invoice_no, supplier_invoice_date: form.supplier_invoice_date, credit_purchase: true, lines: lines.map((line) => ({ product_id: Number(line.product_id), unit_id: Number(line.unit_id), quantity: Number(line.quantity), unit_cost: Number(line.unit_cost), selling_price: Number(line.selling_price || 0), batch_no: line.batch_no || null, expiry_date: line.expiry_date || null })) }; }
      else if (module === 'sales-returns') { path = '/v1/grocery/sales-returns'; body = { sale_id: numeric('sale_id'), store_id: numeric('store_id', options?.stores[0]?.id), reason: form.reason, refund_method: form.refund_method || 'cash', lines: lines.map((line) => ({ sale_line_id: Number(line.sale_line_id), quantity: Number(line.quantity), condition: line.condition || 'saleable' })) }; }
      else if (module === 'purchase-returns') { path = '/v1/grocery/purchase-returns'; body = { goods_receipt_id: numeric('goods_receipt_id') || null, supplier_id: numeric('supplier_id', options?.suppliers[0]?.id), store_id: numeric('store_id', options?.stores[0]?.id), reason: form.reason, lines: lines.map((line) => ({ goods_receipt_line_id: Number(line.goods_receipt_line_id), quantity: Number(line.quantity) })) }; }
      await api.post(path, body);
      setNotice({ type: 'success', text: `${config.title} updated.` }); setOpen(false); setForm({}); setLines([]); await load();
    } catch (error) { setNotice({ type: 'error', text: getApiErrorMessage(error, 'Could not save the record.') }); }
    finally { setSaving(false); }
  }

  return (
    <div className="space-y-6">
      <OperationHeader eyebrow={config.eyebrow} title={config.title} description={config.description} icon={config.icon} actions={<div className="flex flex-wrap gap-2"><button onClick={exportCsv} disabled={!rows.length} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-600 disabled:opacity-40"><Download className="h-4 w-4" /> CSV</button><button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-600"><Printer className="h-4 w-4" /> Print / PDF</button>{config.create && <button onClick={() => { setOpen(true); if (['purchase-orders','goods-receipts','transfers','adjustments','stock-counts','sales-returns','purchase-returns'].includes(module) && !lines.length) addLine(); }} className="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white"><Plus className="h-4 w-4" />{config.create}</button>}</div>} />
      {notice && <OperationNotice type={notice.type}>{notice.text}</OperationNotice>}
      {module === 'reports' && <div className="flex flex-wrap gap-2">{['sales','profit','inventory','expiry','suppliers','shifts','expenses','audit'].map((name) => <button key={name} onClick={() => setReport(name)} className={`rounded-xl px-3 py-2 text-xs font-bold capitalize ${report === name ? 'bg-emerald-600 text-white' : 'border border-slate-200 bg-white text-slate-600'}`}>{name}</button>)}</div>}
      <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between"><input className={`${inputClass} max-w-sm`} value={search} onChange={(event) => setSearch(event.target.value)} placeholder={`Search ${config.title.toLowerCase()}…`} /><span className="text-xs font-semibold text-slate-400">{rows.length} records</span></div>
        <div className="overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wide text-slate-500"><tr>{columns.map((column) => <th key={column} className="whitespace-nowrap px-4 py-3">{column.replaceAll('_', ' ')}</th>)}{['sales','purchase-orders','transfers','shifts','stock-counts'].includes(module) && <th className="px-4 py-3">Actions</th>}</tr></thead><tbody className="divide-y divide-slate-100">{rows.map((row, index) => <tr key={String(row.id || index)} className="hover:bg-slate-50">{columns.map((column) => <td key={column} className="max-w-72 truncate whitespace-nowrap px-4 py-3 text-slate-700">{value(column, row)}</td>)}{['sales','purchase-orders','transfers','shifts','stock-counts'].includes(module) && <td className="whitespace-nowrap px-4 py-3"><div className="flex gap-2">{module === 'sales' && <Link href={`/dashboard/sales/${row.id}/receipt`} className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-600">Receipt</Link>}{module === 'sales' && row.status === 'completed' && <button disabled={saving} onClick={() => void rowAction(row, 'void')} className="rounded-lg bg-rose-50 px-2.5 py-1.5 text-xs font-bold text-rose-700">Void</button>}{module === 'purchase-orders' && row.status === 'draft' && <button disabled={saving} onClick={() => void rowAction(row, 'approve')} className="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700">Approve</button>}{module === 'transfers' && row.status === 'dispatched' && <button disabled={saving} onClick={() => void rowAction(row, 'receive')} className="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700">Receive</button>}{module === 'shifts' && row.status === 'open' && <button disabled={saving} onClick={() => void rowAction(row, 'close')} className="rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-800">Close shift</button>}{module === 'stock-counts' && row.status === 'counting' && <button disabled={saving} onClick={() => void rowAction(row, 'count')} className="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700">Enter & post count</button>}</div></td>}</tr>)}</tbody></table></div>
        {!rows.length && <div className="py-20 text-center text-sm text-slate-400">No records match the current view.</div>}
      </section>

      {open && <OperationModal title={config.create || config.title} description="Required fields are marked by the workflow." onClose={() => setOpen(false)}><form onSubmit={submit} className="space-y-5 p-6"><ModuleForm module={module} form={form} set={set} options={options} products={products} lines={lines} setLines={setLines} addLine={addLine} /><div className="flex justify-end gap-2 border-t border-slate-200 pt-5"><button type="button" onClick={() => setOpen(false)} className="rounded-xl px-4 py-2.5 text-sm font-bold text-slate-500">Cancel</button><button disabled={saving} className="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50">{saving ? 'Saving…' : 'Save changes'}</button></div></form></OperationModal>}
    </div>
  );
}

function ModuleForm({ module, form, set, options, products, lines, setLines, addLine }: { module: Module; form: Record<string, string>; set: (name: string, value: string) => void; options: GroceryOptions | null; products: GroceryProduct[]; lines: Array<Record<string, string>>; setLines: React.Dispatch<React.SetStateAction<Array<Record<string, string>>>>; addLine: () => void }) {
  const field = (name: string, label: string, type = 'text') => <OperationField label={label}><input className={inputClass} type={type} value={form[name] || ''} onChange={(event) => set(name, event.target.value)} required /></OperationField>;
  const select = (name: string, label: string, values: Array<{ value: string | number; label: string }>) => <OperationField label={label}><select className={inputClass} value={form[name] || values[0]?.value || ''} onChange={(event) => set(name, event.target.value)}>{values.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></OperationField>;
  const storeSelect = (name = 'store_id', label = 'Store') => select(name, label, (options?.stores || []).map((item) => ({ value: item.id, label: item.name })));
  const supplierSelect = () => select('supplier_id', 'Supplier', (options?.suppliers || []).map((item) => ({ value: item.id, label: item.name })));
  const hasLines = ['purchase-orders','goods-receipts','transfers','adjustments','stock-counts','sales-returns','purchase-returns'].includes(module);
  return <>
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {module === 'products' && <>{field('sku','SKU')}{field('name','Product name')}{field('barcode','Barcode')}{select('base_unit_id','Base unit',(options?.units || []).map((unit) => ({ value: unit.id, label: `${unit.code} — ${unit.name}` })))}{field('retail_price','Retail price','number')}{field('latest_cost','Opening/latest cost','number')}{field('reorder_level','Reorder level','number')}{select('batch_tracked','Batch tracking',[{value:'false',label:'No'},{value:'true',label:'Yes'}])}{select('expiry_tracked','Expiry tracking',[{value:'false',label:'No'},{value:'true',label:'Yes'}])}{select('weighted','Weighted product',[{value:'false',label:'No'},{value:'true',label:'Yes'}])}</>}
      {module === 'units' && <>{field('code','Code')}{field('name','Name')}{field('decimal_places','Decimal places','number')}</>}
      {module === 'registers' && <>{field('code','Register code')}{field('name','Register name')}{storeSelect()}</>}
      {module === 'promotions' && <>{field('name','Promotion name')}{select('type','Type',['percentage','fixed','price','buy_x_get_y','quantity_break'].map((v) => ({value:v,label:v.replaceAll('_',' ')})))}{select('target_type','Target',['product','category','brand','basket'].map((v) => ({value:v,label:v})))}{select('target_id','Target product',products.map((p) => ({value:p.id,label:p.name})))}{field('value','Discount / price value','number')}{field('minimum_qty','Minimum quantity','number')}{field('starts_at','Starts at','datetime-local')}{field('ends_at','Ends at','datetime-local')}</>}
      {module === 'shifts' && <>{select('register_id','Register',(options?.registers || []).map((r) => ({value:r.id,label:r.name})))}{field('opening_float','Opening float','number')}</>}
      {module === 'expenses' && <>{select('category_id','Category',(options?.expense_categories || []).map((c) => ({value:c.id,label:c.name})))}{field('expense_date','Date','date')}{field('payee','Payee')}{field('amount','Amount','number')}{select('payment_method','Payment method',['cash','card','bank_transfer','mobile'].map((v) => ({value:v,label:v.replaceAll('_',' ')})))}{field('reference','Reference')}</>}
      {module === 'supplier-payments' && <>{supplierSelect()}{field('payment_date','Payment date','date')}{field('amount','Amount','number')}{select('method','Method',['cash','card','bank_transfer','cheque'].map((v) => ({value:v,label:v.replaceAll('_',' ')})))}{field('reference','Reference')}</>}
      {module === 'cash' && <>{select('type','Movement type',['cash_in','cash_out','cash_drop'].map((v) => ({value:v,label:v.replaceAll('_',' ')})))}{field('amount','Amount','number')}{field('reason','Reason')}{field('reference','Reference')}</>}
      {module === 'adjustments' && <>{storeSelect()}{select('reason','Reason',['damage','spoilage','expiry','theft','correction','opening'].map((v) => ({value:v,label:v})))}{field('notes','Notes')}</>}
      {module === 'transfers' && <>{storeSelect('from_store_id','Source store')}{storeSelect('to_store_id','Destination store')}{field('notes','Notes')}</>}
      {module === 'stock-counts' && <>{storeSelect()}{select('type','Count type',[{value:'cycle',label:'Cycle count'},{value:'full',label:'Full count'}])}</>}
      {module === 'purchase-orders' && <>{supplierSelect()}{storeSelect()}{field('order_date','Order date','date')}{field('expected_date','Expected date','date')}{field('notes','Notes')}</>}
      {module === 'goods-receipts' && <>{supplierSelect()}{storeSelect()}{field('supplier_invoice_no','Supplier invoice')}{field('supplier_invoice_date','Invoice date','date')}</>}
      {module === 'sales-returns' && <>{field('sale_id','Sale ID','number')}{storeSelect()}{field('reason','Return reason')}{select('refund_method','Refund method',['cash','card','bank_transfer','mobile','store_credit','exchange'].map((v) => ({value:v,label:v.replaceAll('_',' ')})) )}</>}
      {module === 'purchase-returns' && <>{field('goods_receipt_id','Goods receipt ID','number')}{supplierSelect()}{storeSelect()}{field('reason','Return reason')}</>}
    </div>
    {hasLines && <div className="rounded-2xl border border-slate-200"><div className="flex items-center justify-between border-b border-slate-200 px-4 py-3"><p className="text-sm font-bold">Lines</p><button type="button" onClick={addLine} className="text-xs font-bold text-emerald-700">+ Add line</button></div><div className="space-y-3 p-4">{lines.map((line,index) => { const product = products.find((item) => item.id === Number(line.product_id)) || products[0]; const update = (name:string,value:string) => setLines((current) => current.map((row,i) => i === index ? {...row,[name]:value,...(name === 'product_id' ? {unit_id:String(products.find((p) => p.id === Number(value))?.units[0]?.unit_id || '')}: {})} : row)); return <div key={index} className="grid gap-2 rounded-xl bg-slate-50 p-3 sm:grid-cols-3 lg:grid-cols-6"><select className={inputClass} value={line.product_id || ''} onChange={(e) => update('product_id',e.target.value)}>{products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>{['purchase-orders','goods-receipts'].includes(module) && <select className={inputClass} value={line.unit_id || ''} onChange={(e) => update('unit_id',e.target.value)}>{(product?.units || []).map((u) => <option key={u.unit_id} value={u.unit_id}>{u.code}</option>)}</select>}<input className={inputClass} type="number" step="0.001" value={line.quantity || '1'} onChange={(e) => update('quantity',e.target.value)} placeholder="Quantity" />{['purchase-orders','goods-receipts'].includes(module) && <input className={inputClass} type="number" value={line.unit_cost || ''} onChange={(e) => update('unit_cost',e.target.value)} placeholder="Unit cost" />}{module === 'goods-receipts' && <><input className={inputClass} value={line.batch_no || ''} onChange={(e) => update('batch_no',e.target.value)} placeholder="Batch no." /><input className={inputClass} type="date" value={line.expiry_date || ''} onChange={(e) => update('expiry_date',e.target.value)} /></>}{module === 'sales-returns' && <input className={inputClass} type="number" value={line.sale_line_id || ''} onChange={(e) => update('sale_line_id',e.target.value)} placeholder="Sale line ID" />}{module === 'purchase-returns' && <input className={inputClass} type="number" value={line.goods_receipt_line_id || ''} onChange={(e) => update('goods_receipt_line_id',e.target.value)} placeholder="Receipt line ID" />}<button type="button" onClick={() => setLines((current) => current.filter((_,i) => i !== index))} className="rounded-xl px-3 text-xs font-bold text-rose-600">Remove</button></div>})}</div></div>}
  </>;
}
