'use client';

import { useMemo, useState } from 'react';
import { Barcode, Search } from 'lucide-react';
import { GroceryProduct, money } from '@/lib/grocery';

export function SearchableProductPicker({ products, value, onSelect, currency = 'LKR' }: {
  products: GroceryProduct[];
  value?: number;
  onSelect: (product: GroceryProduct) => void;
  currency?: string;
}) {
  const selected = products.find((product) => product.id === value);
  const [query, setQuery] = useState(() => selected ? `${selected.sku} — ${selected.name}` : '');
  const [open, setOpen] = useState(false);

  const matches = useMemo(() => {
    const term = query.trim().toLowerCase();
    if (!term || (selected && query === `${selected.sku} — ${selected.name}`)) return products.slice(0, 60);
    return products.filter((product) =>
      product.sku.toLowerCase().includes(term)
      || product.name.toLowerCase().includes(term)
      || product.barcodes.some((barcode) => barcode.toLowerCase().includes(term))
    ).slice(0, 60);
  }, [products, query, selected]);

  function choose(product: GroceryProduct) {
    onSelect(product); setQuery(`${product.sku} — ${product.name}`); setOpen(false);
  }

  return <div className="relative">
    <div className="relative">
      <Barcode className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-emerald-600" />
      <input
        aria-label="Scan barcode or search product"
        value={query}
        onFocus={(event) => { setOpen(true); event.currentTarget.select(); }}
        onBlur={() => window.setTimeout(() => setOpen(false), 150)}
        onChange={(event) => { setQuery(event.target.value); setOpen(true); }}
        onKeyDown={(event) => {
          if (event.key !== 'Enter') return;
          event.preventDefault();
          const exact = products.find((product) => product.sku.toLowerCase() === query.trim().toLowerCase() || product.barcodes.includes(query.trim()));
          if (exact) choose(exact); else if (matches.length === 1) choose(matches[0]);
        }}
        placeholder="Scan barcode or search by SKU or product name"
        className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-10 pr-3 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"
      />
    </div>
    {open && <div className="absolute z-[90] mt-2 max-h-72 w-full min-w-[360px] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl shadow-slate-900/15">
      <div className="flex items-center gap-2 px-2 pb-2 text-[11px] font-bold uppercase tracking-wide text-slate-400"><Search className="h-3.5 w-3.5" />{matches.length} matches from {products.length} products</div>
      {matches.map((product) => <button type="button" key={product.id} onMouseDown={(event) => event.preventDefault()} onClick={() => choose(product)} className="grid w-full grid-cols-[1fr_auto] gap-4 rounded-xl px-3 py-2.5 text-left hover:bg-emerald-50">
        <span><span className="block text-sm font-bold text-slate-900">{product.name}</span><span className="font-mono text-[11px] text-slate-500">{product.sku}{product.barcodes[0] ? ` · ${product.barcodes[0]}` : ''}</span></span>
        <span className="text-right"><span className="block text-xs font-bold text-emerald-700">Sell {money(product.retail_price, currency)}</span><span className="text-[11px] text-slate-500">Buy {money(product.latest_cost, currency)}</span></span>
      </button>)}
      {!matches.length && <div className="px-3 py-8 text-center text-sm text-slate-500">No product matches this barcode or search.</div>}
    </div>}
  </div>;
}
