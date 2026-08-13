'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { AlertTriangle, ArrowRight, Boxes, CalendarClock, PackagePlus, ShoppingCart, TrendingUp } from 'lucide-react';
import { api, getApiErrorMessage } from '@/lib/api';
import { DashboardSummary, money } from '@/lib/grocery';
import { OperationHeader, OperationMetric, OperationNotice } from '@/components/operation-ui';

export default function DashboardPage() {
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get<DashboardSummary>('/v1/grocery/dashboard')
      .then((response) => setSummary(response.data || null))
      .catch((reason) => setError(getApiErrorMessage(reason, 'Could not load the dashboard.')));
  }, []);

  return (
    <div className="space-y-7">
      <OperationHeader eyebrow="Today at a glance" title="Grocery operations" description="Sales, stock health, and register activity for your branch." icon={ShoppingCart} actions={<Link href="/dashboard/pos" className="inline-flex items-center gap-2 rounded-xl bg-[#237a55] px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-emerald-900/10 transition hover:bg-[#174a38]">Open POS <ArrowRight className="h-4 w-4" /></Link>} />
      {error && <OperationNotice type="error">{error}</OperationNotice>}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <OperationMetric label="Net sales" value={money(summary?.sales || 0)} tone="emerald" help={`${summary?.transactions || 0} completed baskets`} />
        <OperationMetric label="Gross profit" value={money(summary?.gross_profit || 0)} tone="indigo" />
        <OperationMetric label="Average basket" value={money(summary?.average_basket || 0)} />
        <OperationMetric label="Open registers" value={String(summary?.open_shifts || 0)} tone={(summary?.open_shifts || 0) ? 'amber' : 'slate'} />
      </div>

      <div className="grid gap-5 lg:grid-cols-[1.2fr_.8fr]">
        <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="flex items-center justify-between"><div><p className="text-sm font-bold text-slate-950">Recent sales</p><p className="text-xs text-slate-500">Latest completed checkout activity</p></div><TrendingUp className="h-5 w-5 text-emerald-600" /></div>
          <div className="mt-4 divide-y divide-slate-100">
            {(summary?.recent_sales || []).map((sale) => <div key={String(sale.id)} className="flex items-center justify-between gap-4 py-3"><div><p className="font-mono text-xs font-bold text-slate-700">{String(sale.invoice_no)}</p><p className="text-xs text-slate-400">{new Date(String(sale.sold_at)).toLocaleString()}</p></div><span className="font-bold text-slate-950">{money(Number(sale.grand_total))}</span></div>)}
            {!summary?.recent_sales?.length && <p className="py-14 text-center text-sm text-slate-400">The first completed sale will appear here.</p>}
          </div>
        </section>

        <div className="space-y-5">
          <section className="rounded-3xl bg-[#12382b] p-5 text-white shadow-xl shadow-emerald-950/10">
            <p className="text-xs font-bold uppercase tracking-[.18em] text-emerald-200/80">Stock attention</p>
            <div className="mt-5 grid grid-cols-2 gap-3"><Link href="/dashboard/reorder-alerts" className="rounded-2xl bg-white/[0.08] p-4 ring-1 ring-white/10 transition hover:bg-white/[0.13]"><AlertTriangle className="h-5 w-5 text-[#e3a32b]" /><p className="data-figure mt-4 text-2xl font-black">{summary?.low_stock_count || 0}</p><p className="text-xs text-emerald-50/65">Low-stock products</p></Link><Link href="/dashboard/expiry" className="rounded-2xl bg-white/[0.08] p-4 ring-1 ring-white/10 transition hover:bg-white/[0.13]"><CalendarClock className="h-5 w-5 text-rose-300" /><p className="data-figure mt-4 text-2xl font-black">{summary?.near_expiry_count || 0}</p><p className="text-xs text-emerald-50/65">Near-expiry batches</p></Link></div>
          </section>
          <section className="grid grid-cols-2 gap-3">
            <Link href="/dashboard/goods-receipts" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-emerald-300"><PackagePlus className="h-5 w-5 text-emerald-600" /><p className="mt-3 text-sm font-bold">Receive stock</p></Link>
            <Link href="/dashboard/inventory" className="rounded-2xl border border-[#dce5de] bg-white p-4 shadow-sm transition hover:border-[#2d8f63]"><Boxes className="h-5 w-5 text-[#237a55]" /><p className="mt-3 text-sm font-bold">Check inventory</p></Link>
          </section>
        </div>
      </div>
    </div>
  );
}
