'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowLeft, Printer } from 'lucide-react';
import { api, getApiErrorMessage } from '@/lib/api';
import { money, quantity } from '@/lib/grocery';

type ReceiptLine = {
  id: number;
  description: string;
  sku: string;
  unit_code: string;
  quantity: number;
  unit_price: number;
  discount_total: number;
  tax_total: number;
  line_total: number;
};

type ReceiptPayment = { id: number; method: string; amount: number; tendered: number; change_amount: number; reference?: string | null };

type ReceiptSale = {
  id: number;
  invoice_no: string;
  sold_at: string;
  status: string;
  customer_name?: string | null;
  store_name?: string | null;
  register_name?: string | null;
  subtotal: number;
  discount_total: number;
  tax_total: number;
  grand_total: number;
  print_count: number;
  lines: ReceiptLine[];
  payments: ReceiptPayment[];
};

export default function ReceiptView({ saleId }: { saleId: string }) {
  const [sale, setSale] = useState<ReceiptSale | null>(null);
  const [error, setError] = useState('');
  const [printing, setPrinting] = useState(false);

  useEffect(() => {
    void api.get<ReceiptSale>(`/v1/grocery/sales/${saleId}`)
      .then((response) => setSale(response.data || null))
      .catch((reason) => setError(getApiErrorMessage(reason, 'Could not load this receipt.')));
  }, [saleId]);

  async function printReceipt() {
    setPrinting(true);
    setError('');
    try {
      const response = await api.post<ReceiptSale>(`/v1/grocery/sales/${saleId}/print`, {});
      if (response.data) setSale(response.data);
      window.setTimeout(() => window.print(), 50);
    } catch (reason) {
      setError(getApiErrorMessage(reason, 'Could not record the receipt print.'));
    } finally {
      setPrinting(false);
    }
  }

  if (error && !sale) return <div className="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm font-semibold text-rose-800">{error}</div>;
  if (!sale) return <div className="p-10 text-center text-sm font-semibold text-slate-500">Loading receipt...</div>;

  return (
    <div className="mx-auto max-w-xl space-y-4">
      <div className="flex items-center justify-between gap-3 print:hidden">
        <Link href="/dashboard/sales" className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700"><ArrowLeft className="h-4 w-4" /> Sales</Link>
        <button onClick={() => void printReceipt()} disabled={printing || sale.status !== 'completed'} className="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50"><Printer className="h-4 w-4" />{printing ? 'Preparing...' : sale.print_count ? 'Reprint receipt' : 'Print receipt'}</button>
      </div>
      {error && <div className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700 print:hidden">{error}</div>}
      <article className="receipt-sheet mx-auto bg-white p-7 font-mono text-[12px] leading-relaxed text-slate-950 shadow-sm ring-1 ring-slate-200 print:p-0 print:shadow-none print:ring-0">
        <header className="text-center">
          <h1 className="text-lg font-black tracking-tight">GROCERY ERP</h1>
          <p>{sale.store_name || 'Grocery Shop'}</p>
          <p className="mt-2 text-[11px]">{sale.print_count > 1 ? `REPRINT COPY #${sale.print_count}` : 'SALES RECEIPT'}</p>
        </header>
        <div className="my-4 border-y border-dashed border-slate-500 py-3">
          <div className="flex justify-between"><span>Invoice</span><strong>{sale.invoice_no}</strong></div>
          <div className="flex justify-between"><span>Date</span><span>{new Date(sale.sold_at).toLocaleString()}</span></div>
          <div className="flex justify-between"><span>Register</span><span>{sale.register_name || '-'}</span></div>
          <div className="flex justify-between"><span>Customer</span><span>{sale.customer_name || 'Walk-in Customer'}</span></div>
        </div>
        <div className="space-y-3">
          {sale.lines.map((line) => <div key={line.id}><div className="font-bold">{line.description}</div><div className="flex justify-between"><span>{quantity(line.quantity)} {line.unit_code} x {money(line.unit_price)}</span><span>{money(line.line_total)}</span></div>{Number(line.discount_total) > 0 && <div className="flex justify-between text-[11px]"><span>Discount</span><span>-{money(line.discount_total)}</span></div>}</div>)}
        </div>
        <div className="my-4 space-y-1 border-y border-dashed border-slate-500 py-3">
          <div className="flex justify-between"><span>Subtotal</span><span>{money(sale.subtotal)}</span></div>
          <div className="flex justify-between"><span>Discount</span><span>-{money(sale.discount_total)}</span></div>
          <div className="flex justify-between"><span>Tax included/added</span><span>{money(sale.tax_total)}</span></div>
          <div className="flex justify-between text-base font-black"><span>TOTAL</span><span>{money(sale.grand_total)}</span></div>
        </div>
        <div className="space-y-1">{sale.payments.map((payment) => <div key={payment.id} className="flex justify-between"><span className="capitalize">{payment.method}</span><span>{money(payment.amount)}</span></div>)}</div>
        <footer className="mt-6 text-center"><p>Thank you for shopping with us.</p><p className="mt-2 text-[10px]">Returns require this receipt and manager approval.</p></footer>
      </article>
    </div>
  );
}
