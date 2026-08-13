'use client';

import { useEffect, useId, useMemo, useState } from 'react';
import { Search } from 'lucide-react';
import { api } from '@/lib/api';

export interface LookupEntity {
  id: number;
  name: string;
  Code?: string;
  phone?: string | null;
}

export function SearchableEntityPicker({
  resource,
  items,
  value,
  onSelect,
  label,
}: {
  resource: 'customers' | 'suppliers';
  items: LookupEntity[];
  value?: number | null;
  onSelect: (item: LookupEntity) => void;
  label: string;
}) {
  const selected = items.find((item) => item.id === value);
  const [query, setQuery] = useState(selected?.name || '');
  const [remote, setRemote] = useState<LookupEntity[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const listId = useId();

  useEffect(() => {
    const term = query.trim();
    if (!open || term.length < 2 || term === selected?.name) return;
    const timer = window.setTimeout(async () => {
      setLoading(true);
      try {
        const response = await api.get<LookupEntity[]>(
          `/v1/grocery/lookups/${resource}?limit=40&search=${encodeURIComponent(term)}`,
        );
        setRemote(response.data || []);
      } catch {
        setRemote([]);
      } finally {
        setLoading(false);
      }
    }, 180);
    return () => window.clearTimeout(timer);
  }, [open, query, resource, selected?.name]);

  const matches = useMemo(() => {
    const term = query.trim().toLowerCase();
    const combined = new Map([...items, ...remote].map((item) => [item.id, item]));
    return Array.from(combined.values()).filter((item) =>
      !term || item.name.toLowerCase().includes(term)
      || String(item.Code || '').toLowerCase().includes(term)
      || String(item.phone || '').includes(term)
    ).slice(0, 40);
  }, [items, query, remote]);

  function choose(item: LookupEntity) {
    onSelect(item);
    setQuery(item.name);
    setRemote([]);
    setOpen(false);
  }

  return <div className="relative">
    <div className="relative">
      <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
      <input
        role="combobox"
        aria-label={label}
        aria-expanded={open}
        aria-controls={listId}
        value={query}
        onFocus={(event) => { setOpen(true); event.currentTarget.select(); }}
        onBlur={() => window.setTimeout(() => setOpen(false), 150)}
        onChange={(event) => { setQuery(event.target.value); setRemote([]); setOpen(true); }}
        className="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-9 pr-3 text-sm text-slate-900 outline-none focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100"
        placeholder={`Search ${label.toLowerCase()}`}
      />
    </div>
    {open && <div id={listId} role="listbox" className="absolute z-[150] mt-2 max-h-64 w-full min-w-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-2xl">
      <p className="px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-400">{loading ? 'Searching...' : `${matches.length} matches`}</p>
      {matches.map((item) => <button key={item.id} type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => choose(item)} className="flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-left text-sm hover:bg-emerald-50">
        <span className="font-bold text-slate-800">{item.name}</span>
        <span className="ml-3 text-[10px] text-slate-400">{item.Code || item.phone || ''}</span>
      </button>)}
      {!matches.length && !loading && <p className="px-3 py-5 text-center text-xs text-slate-500">No match found.</p>}
    </div>}
  </div>;
}
