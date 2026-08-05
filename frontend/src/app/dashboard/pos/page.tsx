'use client';

import { FormEvent, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { Barcode, Banknote, CreditCard, Maximize2, Minimize2, Pause, Play, Plus, Printer, ReceiptText, Search, ShoppingCart, Trash2, WalletCards } from 'lucide-react';
import { api, getApiErrorMessage } from '@/lib/api';
import { GroceryOptions, GroceryProduct, GroceryUnit, PosLine, money as formatMoney, quantity } from '@/lib/grocery';
import { OperationField, OperationHeader, OperationNotice } from '@/components/operation-ui';

const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100';

export default function PointOfSalePage() {
  const [options, setOptions] = useState<GroceryOptions | null>(null);
  const [products, setProducts] = useState<GroceryProduct[]>([]);
  const [cart, setCart] = useState<PosLine[]>([]);
  const [search, setSearch] = useState('');
  const [storeId, setStoreId] = useState(0);
  const [customerId, setCustomerId] = useState<number | null>(null);
  const [paymentMethod, setPaymentMethod] = useState('cash');
  const [splitPayment, setSplitPayment] = useState(false);
  const [secondaryMethod, setSecondaryMethod] = useState('card');
  const [secondaryAmount, setSecondaryAmount] = useState('');
  const [tendered, setTendered] = useState('');
  const [openingFloat, setOpeningFloat] = useState('0');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [lastSale, setLastSale] = useState<{ id: number; invoice_no: string } | null>(null);
  const [heldSales, setHeldSales] = useState<Array<{ id: number; invoice_no: string; grand_total: number }>>([]);
  const [heldSaleId, setHeldSaleId] = useState<number | null>(null);
  const [resumeOpen, setResumeOpen] = useState(false);
  const [focusMode, setFocusMode] = useState(false);
  const searchRef = useRef<HTMLInputElement>(null);
  const money = (value: number | string | null | undefined) => formatMoney(value, String(options?.company?.currency || 'LKR'));

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const optionResponse = await api.get<GroceryOptions>('/v1/grocery/options');
      const nextOptions = optionResponse.data!;
      setOptions(nextOptions);
      const nextStore = storeId || (nextOptions.open_shift
        ? nextOptions.registers.find((register) => register.id === nextOptions.open_shift?.register_id)?.store_id
        : nextOptions.stores[0]?.id);
      const resolvedStore = Number(nextStore || nextOptions.stores[0]?.id || 0);
      setStoreId(resolvedStore);
      const productResponse = await api.get<GroceryProduct[]>(`/v1/grocery/products?store_id=${resolvedStore}`);
      setProducts(productResponse.data || []);
      const heldResponse = await api.get<{ data: Array<{ id: number; invoice_no: string; grand_total: number }> }>('/v1/grocery/sales?status=held');
      setHeldSales(heldResponse.data?.data || []);
      if (!customerId) setCustomerId(nextOptions.customers.find((customer) => customer.Code === 'WALK-IN')?.id || null);
    } catch (error) {
      setNotice({ type: 'error', text: getApiErrorMessage(error, 'Could not load the checkout.') });
    } finally {
      setLoading(false);
      window.setTimeout(() => searchRef.current?.focus(), 50);
    }
  }, [customerId, storeId]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const handler = (event: KeyboardEvent) => {
      if (event.key === 'F2') { event.preventDefault(); searchRef.current?.focus(); }
      if (event.key === 'F6' && cart.length && !saving) { event.preventDefault(); void saveSale(true); }
      if (event.key === 'F7' && heldSales.length) { event.preventDefault(); setResumeOpen((current) => !current); }
      if (event.key === 'F8') { event.preventDefault(); void completeSale(); }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  });

  useEffect(() => {
    const sync = () => { if (!document.fullscreenElement) setFocusMode(false); };
    document.addEventListener('fullscreenchange', sync);
    return () => document.removeEventListener('fullscreenchange', sync);
  }, []);

  const pricing = useMemo(() => cart.map((line) => {
    const gross = line.quantity * line.unitPrice;
    const eligible = (options?.promotions || []).filter((promotion) => {
      const now = Date.now(); const starts = new Date(promotion.starts_at).getTime(); const ends = new Date(promotion.ends_at).getTime();
      const target = promotion.target_type === 'basket' || (promotion.target_type === 'product' && promotion.target_id === line.product.id) || (promotion.target_type === 'category' && promotion.target_id === line.product.category_id) || (promotion.target_type === 'brand' && promotion.target_id === line.product.brand_id);
      return target && now >= starts && now <= ends && (!promotion.minimum_qty || line.quantity >= Number(promotion.minimum_qty)) && (!promotion.minimum_subtotal || gross >= Number(promotion.minimum_subtotal));
    });
    const promotionDiscount = eligible.reduce((best, promotion) => {
      const value = Number(promotion.value); let next = 0;
      if (promotion.type === 'percentage' || promotion.type === 'quantity_break') next = gross * value / 100;
      if (promotion.type === 'fixed') next = value;
      if (promotion.type === 'price') next = Math.max(0, gross - line.quantity * value);
      if (promotion.type === 'buy_x_get_y' && promotion.buy_qty && promotion.get_qty) next = Math.floor(line.quantity / (Number(promotion.buy_qty) + Number(promotion.get_qty))) * Number(promotion.get_qty) * (gross / line.quantity);
      return Math.max(best, next);
    }, 0);
    const lineDiscount = Math.min(gross, Math.max(line.discount, promotionDiscount));
    const afterDiscount = gross - lineDiscount; const rate = Number(line.product.tax_rate || 0);
    const lineTax = Boolean(line.product.tax_inclusive) ? afterDiscount - afterDiscount / (1 + rate / 100) : afterDiscount * rate / 100;
    const lineTotal = Boolean(line.product.tax_inclusive) ? afterDiscount : afterDiscount + lineTax;
    return { key: line.key, gross, discount: lineDiscount, tax: lineTax, total: lineTotal };
  }), [cart, options?.promotions]);
  const subtotal = pricing.reduce((sum, line) => sum + line.gross, 0);
  const discount = pricing.reduce((sum, line) => sum + line.discount, 0);
  const tax = pricing.reduce((sum, line) => sum + line.tax, 0);
  const total = Math.max(0, pricing.reduce((sum, line) => sum + line.total, 0));
  const secondaryDue = splitPayment ? Math.min(total, Math.max(0, Number(secondaryAmount || 0))) : 0;
  const primaryDue = Math.max(0, total - secondaryDue);
  const change = Math.max(0, Number(tendered || 0) - primaryDue);
  const paymentMethods: Array<[string, typeof Banknote]> = [
    ['cash', Banknote], ['card', CreditCard], ['bank_transfer', CreditCard], ['mobile', WalletCards],
    ...(Boolean(options?.company?.customer_credit_enabled) ? [['credit', WalletCards] as [string, typeof Banknote], ['store_credit', WalletCards] as [string, typeof Banknote]] : []),
  ];

  const filtered = useMemo(() => {
    const value = search.trim().toLowerCase();
    if (!value) return products.slice(0, 12);
    return products.filter((product) => product.sku.toLowerCase().includes(value) || product.name.toLowerCase().includes(value) || product.barcodes.some((barcode) => barcode === search.trim())).slice(0, 20);
  }, [products, search]);

  function addProduct(product: GroceryProduct, scannedQuantity = 1) {
    const unit = product.units.find((candidate) => candidate.unit_id === product.base_unit_id) || product.units[0];
    if (!unit) return;
    const key = `${product.id}:${unit.unit_id}`;
    setCart((current) => {
      const existing = current.find((line) => line.key === key);
      if (existing) return current.map((line) => line.key === key ? { ...line, quantity: line.quantity + scannedQuantity } : line);
      return [...current, { key, product, unit, quantity: scannedQuantity, unitPrice: Number(unit.selling_price ?? product.retail_price), discount: 0 }];
    });
    setSearch('');
    searchRef.current?.focus();
  }

  function submitSearch(event: FormEvent) {
    event.preventDefault();
    const prefix = String(options?.company?.scale_barcode_prefix || '');
    const productDigits = Number(options?.company?.scale_product_digits || 5);
    const weightDigits = Number(options?.company?.scale_weight_digits || 5);
    if (prefix && search.startsWith(prefix) && search.length >= prefix.length + productDigits + weightDigits) {
      const productCode = search.slice(prefix.length, prefix.length + productDigits);
      const weight = Number(search.slice(prefix.length + productDigits, prefix.length + productDigits + weightDigits)) / 1000;
      const scaledProduct = products.find((product) => product.sku === productCode || product.barcodes.some((barcode) => barcode === `${prefix}${productCode}`));
      if (scaledProduct && weight > 0) { addProduct(scaledProduct, weight); return; }
    }
    const exact = products.find((product) => product.barcodes.includes(search.trim()) || product.sku.toLowerCase() === search.trim().toLowerCase());
    if (exact) addProduct(exact);
    else if (filtered.length === 1) addProduct(filtered[0]);
  }

  function updateLine(key: string, update: Partial<Pick<PosLine, 'quantity' | 'unit' | 'unitPrice' | 'discount'>>) {
    setCart((current) => current.map((line) => {
      if (line.key !== key) return line;
      const unit = update.unit || line.unit;
      return { ...line, ...update, key: `${line.product.id}:${unit.unit_id}`, unit, unitPrice: update.unit ? Number(update.unit.selling_price ?? line.product.retail_price) : (update.unitPrice ?? line.unitPrice) };
    }));
  }

  async function resumeHeldSale(id: number) {
    if (cart.length && !window.confirm('Resume this paused sale and replace the current basket?')) return;
    setSaving(true); setNotice(null);
    try {
      const response = await api.get<{ id: number; store_id: number; lines: Array<{ product_id: number; unit_id: number; quantity: number; unit_price: number; discount_total: number }> }>(`/v1/grocery/sales/${id}`);
      const held = response.data;
      if (!held) return;
      const resumed = held.lines.flatMap((line) => {
        const product = products.find((candidate) => candidate.id === line.product_id);
        const unit = product?.units.find((candidate) => candidate.unit_id === line.unit_id);
        return product && unit ? [{ key: `${product.id}:${unit.unit_id}`, product, unit, quantity: Number(line.quantity), unitPrice: Number(line.unit_price), discount: Number(line.discount_total) }] : [];
      });
      setCart(resumed); setStoreId(Number(held.store_id)); setHeldSaleId(id);
      setHeldSales((current) => current.filter((sale) => sale.id !== id)); setResumeOpen(false);
      setNotice({ type: 'success', text: 'Paused sale resumed. Complete payment or pause it again.' });
      searchRef.current?.focus();
    } catch (error) { setNotice({ type: 'error', text: getApiErrorMessage(error, 'Could not resume the held sale.') }); }
    finally { setSaving(false); }
  }

  async function openShift() {
    if (!options?.registers[0]) return;
    setSaving(true);
    try {
      await api.post('/v1/grocery/shifts/open', { register_id: options.registers[0].id, opening_float: Number(openingFloat) });
      setNotice({ type: 'success', text: 'Register shift opened. Checkout is ready.' });
      await load();
    } catch (error) { setNotice({ type: 'error', text: getApiErrorMessage(error) }); }
    finally { setSaving(false); }
  }

  async function saveSale(hold: boolean) {
    if (!cart.length || !storeId) return;
    setSaving(true); setNotice(null);
    try {
      const body = {
        store_id: storeId, register_id: options?.open_shift?.register_id, shift_id: options?.open_shift?.id,
        held_sale_id: heldSaleId,
        customer_id: customerId,
        lines: cart.map((line) => ({ product_id: line.product.id, unit_id: line.unit.unit_id, quantity: line.quantity, unit_price: line.unitPrice, discount: line.discount })),
        payments: hold ? [] : [
          ...(primaryDue > 0 ? [{ method: paymentMethod, amount: primaryDue, tendered: paymentMethod === 'cash' ? Number(tendered || primaryDue) : primaryDue }] : []),
          ...(secondaryDue > 0 ? [{ method: secondaryMethod, amount: secondaryDue, tendered: secondaryDue }] : []),
        ],
      };
      const response = await api.post<{ id: number; invoice_no: string }>(hold ? '/v1/grocery/pos/hold' : '/v1/grocery/pos/complete', body);
      setNotice({ type: 'success', text: hold ? `Sale ${response.data?.invoice_no} paused.` : `Sale ${response.data?.invoice_no} completed. Receipt is ready.` });
      setLastSale(!hold && response.data ? { id: response.data.id, invoice_no: response.data.invoice_no } : null);
      setCart([]); setTendered(''); setSecondaryAmount(''); setSplitPayment(false); setHeldSaleId(null); await load();
    } catch (error) { setNotice({ type: 'error', text: getApiErrorMessage(error, 'Could not complete the sale.') }); }
    finally { setSaving(false); }
  }

  async function completeSale() { await saveSale(false); }

  async function toggleFocusMode() {
    if (!focusMode) { try { await document.documentElement.requestFullscreen?.(); } catch {} setFocusMode(true); }
    else { if (document.fullscreenElement) await document.exitFullscreen(); setFocusMode(false); }
  }

  if (loading && !options) return <div className="flex min-h-[60vh] items-center justify-center text-sm font-semibold text-slate-500">Preparing checkout…</div>;

  return (
    <div className={focusMode ? 'fixed inset-0 z-[100] overflow-y-auto bg-slate-100 p-3' : 'space-y-5'}>
      {!focusMode && <OperationHeader eyebrow="Front counter" title="Point of sale" description="Scan and serve continuously. F6 pauses, F7 opens paused sales, and F8 takes payment." icon={ShoppingCart} actions={<button onClick={() => void toggleFocusMode()} className="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white"><Maximize2 className="h-4 w-4" /> Full-screen checkout</button>} />}
      {focusMode && <button onClick={() => void toggleFocusMode()} className="fixed right-5 top-5 z-[110] inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-slate-800 shadow-xl ring-1 ring-slate-200"><Minimize2 className="h-4 w-4" /> Exit full screen</button>}
      {notice && <OperationNotice type={notice.type}>{notice.text}</OperationNotice>}
      {lastSale && <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950"><span><strong>{lastSale.invoice_no}</strong> is complete and ready to print.</span><Link href={`/dashboard/sales/${lastSale.id}/receipt`} className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-2 font-bold text-white"><Printer className="h-4 w-4" /> Print receipt</Link></div>}
      {!options?.open_shift && (
        <section className="rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div><p className="font-bold text-amber-950">Open the register first</p><p className="mt-1 text-sm text-amber-800">Enter the opening cash float before accepting payments.</p></div>
            <div className="flex items-end gap-2"><OperationField label="Opening float"><input className={inputClass} value={openingFloat} onChange={(event) => setOpeningFloat(event.target.value)} type="number" min="0" /></OperationField><button onClick={openShift} disabled={saving} className="rounded-xl bg-amber-950 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50">Open shift</button></div>
          </div>
        </section>
      )}

      <div className={`grid gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(360px,.75fr)] ${focusMode ? 'min-h-[calc(100vh-1.5rem)]' : 'min-h-[650px]'}`}>
        <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="grid gap-3 border-b border-slate-200 bg-slate-950 p-4 text-white lg:grid-cols-[1fr_260px]">
            <form onSubmit={submitSearch} className="relative">
              <Barcode className="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-emerald-300" />
              <input ref={searchRef} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Scan barcode or search product (F2)" className="w-full rounded-2xl border border-white/15 bg-white/10 py-3.5 pl-12 pr-4 text-base text-white outline-none placeholder:text-slate-400 focus:border-emerald-400 focus:ring-4 focus:ring-emerald-400/15" />
            </form><select aria-label="Customer" className="rounded-2xl border border-white/15 bg-slate-900 px-4 py-3 text-sm text-white outline-none focus:border-emerald-400" value={customerId || ''} onChange={(event) => setCustomerId(Number(event.target.value) || null)}>{(options?.customers || []).map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select>
          </div>
          <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
            {filtered.map((product) => (
              <button key={product.id} onClick={() => addProduct(product)} className="group min-h-28 rounded-2xl border border-slate-200 p-4 text-left transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-lg hover:shadow-emerald-100">
                <div className="flex items-start justify-between gap-3"><span className="rounded-lg bg-slate-100 px-2 py-1 font-mono text-[11px] font-bold text-slate-500">{product.sku}</span><Plus className="h-4 w-4 text-emerald-600 opacity-0 transition group-hover:opacity-100" /></div>
                <p className="mt-3 line-clamp-2 text-sm font-bold text-slate-950">{product.name}</p>
                <div className="mt-2 flex items-end justify-between"><span className="text-xs text-slate-500">{quantity(product.stock)} {product.base_unit_code}</span><span className="font-bold text-emerald-700">{money(product.retail_price)}</span></div>
              </button>
            ))}
            {!filtered.length && <div className="col-span-full flex min-h-64 flex-col items-center justify-center text-center text-slate-400"><Search className="h-8 w-8" /><p className="mt-3 text-sm font-semibold">No product matches this scan.</p></div>}
          </div>
        </section>

        <aside className="flex min-h-0 flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4"><div><p className="font-bold text-slate-950">Current basket</p><p className="text-xs text-slate-500">{cart.length} product lines</p></div><ReceiptText className="h-5 w-5 text-slate-400" /></div>
          <div className="content-scrollbar min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
            {cart.map((line) => (
              <div key={line.key} className="rounded-2xl border border-slate-200 p-3">
                <div className="flex items-start justify-between gap-2"><div><p className="text-sm font-bold text-slate-950">{line.product.name}</p><p className="text-[11px] text-slate-500">{line.product.sku}</p></div><button onClick={() => setCart((current) => current.filter((item) => item.key !== line.key))} className="rounded-lg p-1.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove line"><Trash2 className="h-4 w-4" /></button></div>
                <div className="mt-3 grid grid-cols-[1fr_1fr] gap-2">
                  <select className={inputClass} value={line.unit.unit_id} onChange={(event) => updateLine(line.key, { unit: line.product.units.find((unit) => unit.unit_id === Number(event.target.value)) as GroceryUnit })}>{line.product.units.map((unit) => <option key={unit.unit_id} value={unit.unit_id}>{unit.code}</option>)}</select>
                  <input className={inputClass} type="number" min="0.001" step={line.product.allow_decimal_qty ? '0.001' : '1'} value={line.quantity} onChange={(event) => updateLine(line.key, { quantity: Number(event.target.value) })} />
                </div>
                <div className="mt-2 flex items-center justify-between text-sm"><span className="text-slate-500">{money(line.unitPrice)} each</span><span className="font-bold text-slate-950">{money(pricing.find((item) => item.key === line.key)?.total || 0)}</span></div>
              </div>
            ))}
            {!cart.length && <div className="flex min-h-56 flex-col items-center justify-center text-center text-slate-400"><ShoppingCart className="h-9 w-9" /><p className="mt-3 text-sm font-semibold">Scan the first item to begin.</p></div>}
          </div>
          <div className="border-t border-slate-200 bg-slate-50 p-4">
            <div className="space-y-2 text-sm"><div className="flex justify-between text-slate-500"><span>Subtotal</span><span>{money(subtotal)}</span></div><div className="flex justify-between text-slate-500"><span>Discount</span><span>-{money(discount)}</span></div>{tax > 0 && <div className="flex justify-between text-slate-500"><span>Tax</span><span>{money(tax)}</span></div>}<div className="flex justify-between border-t border-slate-200 pt-3 text-xl font-black text-slate-950"><span>Total</span><span>{money(total)}</span></div></div>
            <div className="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4">{paymentMethods.map(([method, PaymentIcon]) => <button key={method} onClick={() => setPaymentMethod(method)} className={`rounded-xl border p-2 text-[11px] font-bold capitalize transition ${paymentMethod === method ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-600'}`}><PaymentIcon className="mx-auto mb-1 h-4 w-4" />{method.replaceAll('_', ' ')}</button>)}</div>
            <label className="mt-3 flex items-center gap-2 text-xs font-bold text-slate-600"><input type="checkbox" checked={splitPayment} onChange={(event) => setSplitPayment(event.target.checked)} className="h-4 w-4 accent-emerald-600" /> Split payment</label>
            {splitPayment && <div className="mt-2 grid grid-cols-2 gap-2"><OperationField label="Second method"><select className={inputClass} value={secondaryMethod} onChange={(event) => setSecondaryMethod(event.target.value)}><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="mobile">Mobile / QR</option><option value="cash">Cash</option></select></OperationField><OperationField label="Second amount"><input className={inputClass} type="number" min="0.01" max={total} value={secondaryAmount} onChange={(event) => setSecondaryAmount(event.target.value)} /></OperationField></div>}
            {splitPayment && <div className="mt-2 flex justify-between rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-900"><span>Primary payment</span><span>{money(primaryDue)}</span></div>}
            {paymentMethod === 'cash' && <div className="mt-3 grid grid-cols-2 gap-2"><OperationField label="Cash received"><input className={inputClass} type="number" min={primaryDue} value={tendered} onChange={(event) => setTendered(event.target.value)} placeholder={primaryDue.toFixed(2)} /></OperationField><div className="rounded-xl bg-white p-3 ring-1 ring-slate-200"><p className="text-[11px] font-semibold uppercase text-slate-400">Change</p><p className="mt-1 font-black text-emerald-700">{money(change)}</p></div></div>}
            {resumeOpen && <div className="mt-4 max-h-44 space-y-2 overflow-y-auto rounded-2xl border border-amber-200 bg-amber-50 p-3"><div className="flex items-center justify-between"><p className="text-xs font-black uppercase tracking-wide text-amber-950">Paused sales</p><span className="rounded-full bg-amber-200 px-2 py-0.5 text-[10px] font-black text-amber-950">{heldSales.length}</span></div>{heldSales.map((sale) => <button key={sale.id} onClick={() => void resumeHeldSale(sale.id)} disabled={saving} className="flex w-full items-center justify-between rounded-xl border border-amber-200 bg-white px-3 py-2.5 text-left text-xs font-bold text-amber-950 transition hover:border-amber-400 hover:bg-amber-100"><span>{sale.invoice_no}</span><span className="font-semibold text-amber-700">{money(sale.grand_total)}</span></button>)}</div>}
            <div className="mt-4 grid grid-cols-2 gap-2"><button title={cart.length ? 'Pause this sale (F6)' : 'Add an item before pausing'} disabled={!cart.length || saving} onClick={() => void saveSale(true)} className="inline-flex items-center justify-center gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-black text-amber-950 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40"><Pause className="h-4 w-4" /> Pause sale <span className="text-[10px] font-semibold text-amber-700">F6</span></button><button title={heldSales.length ? 'Resume a paused sale (F7)' : 'No paused sales'} disabled={!heldSales.length || saving} aria-expanded={resumeOpen} onClick={() => setResumeOpen((current) => !current)} className="inline-flex items-center justify-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-black text-emerald-950 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-40"><Play className="h-4 w-4" /> Resume sale <span className="rounded-full bg-emerald-200 px-1.5 py-0.5 text-[10px] font-black">{heldSales.length}</span></button></div>
            <button disabled={!cart.length || saving || !options?.open_shift || (splitPayment && secondaryDue <= 0)} onClick={() => void completeSale()} className="mt-2 w-full rounded-xl bg-slate-950 px-5 py-3 text-sm font-bold text-white shadow-lg disabled:opacity-40">{saving ? 'Processing…' : `Take payment · ${money(total)} (F8)`}</button>
          </div>
        </aside>
      </div>
    </div>
  );
}
