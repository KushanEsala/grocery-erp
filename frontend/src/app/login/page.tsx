'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import {
  AlertCircle,
  CheckCircle2,
  LoaderCircle,
  LockKeyhole,
  Mail,
  ShoppingBasket,
} from 'lucide-react';
import { useAuth } from '@/lib/auth-context';
import { getApiErrorMessage } from '@/lib/api';

export default function LoginPage() {
  const [email, setEmail] = useState('admin@erp.com');
  const [password, setPassword] = useState('password');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const { login } = useAuth();
  const router = useRouter();

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError('');
    setLoading(true);

    try {
      await login(email, password);
      const redirectTo =
        sessionStorage.getItem('redirect_after_login') || '/dashboard';
      sessionStorage.removeItem('redirect_after_login');
      router.replace(redirectTo);
    } catch (requestError: unknown) {
      setError(
        getApiErrorMessage(
          requestError,
          'Invalid credentials. Please try again.'
        )
      );
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-[#e9f0eb] p-4 sm:p-8">
      <main className="grid w-full max-w-4xl overflow-hidden rounded-3xl bg-white shadow-2xl shadow-[#12382b]/15 ring-1 ring-[#12382b]/10 md:grid-cols-[.9fr_1.1fr]">
        <section className="relative hidden min-h-[610px] flex-col justify-between overflow-hidden bg-[#12382b] p-10 text-white md:flex">
          <div className="market-stripe absolute inset-x-0 top-0 h-1.5" aria-hidden="true" />
          <div>
            <span className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-[#e3a32b] text-[#12382b]">
              <ShoppingBasket className="h-6 w-6" />
            </span>
            <p className="mt-8 text-xs font-bold uppercase tracking-[0.2em] text-emerald-200/75">Grocery ERP</p>
            <h1 className="mt-3 max-w-sm text-3xl font-bold leading-tight">Run the shop from one clear workspace.</h1>
            <p className="mt-4 max-w-sm text-sm leading-6 text-emerald-50/70">Checkout, purchasing, stock, expiry and accounts stay connected from the shelf to the daily report.</p>
          </div>
          <div className="space-y-3 border-t border-white/10 pt-6 text-sm text-emerald-50/80">
            {['Fast cashier workflow', 'Batch and expiry visibility', 'Branch-level access control'].map((item) => (
              <div key={item} className="flex items-center gap-3"><CheckCircle2 className="h-4 w-4 text-[#e3a32b]" />{item}</div>
            ))}
          </div>
        </section>

        <section className="relative p-7 sm:p-10 md:p-12">
          <div className="mb-8 flex items-center gap-3 md:hidden">
            <span className="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-[#12382b] text-white"><ShoppingBasket className="h-5 w-5" /></span>
            <div><p className="font-bold text-[#17211c]">Grocery ERP</p><p className="text-xs text-slate-500">Retail operations</p></div>
          </div>

          <div className="mb-6">
            <p className="text-xs font-bold uppercase tracking-[0.18em] text-[#237a55]">Secure access</p>
            <h2 className="mt-2 text-2xl font-bold text-[#17211c]">Sign in to your workspace</h2>
            <p className="mt-2 text-sm text-slate-500">
              Use the account assigned to your store or branch.
            </p>
          </div>

          <form onSubmit={handleSubmit} className="space-y-5">
            {error && (
              <div className="flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <AlertCircle className="h-5 w-5 flex-shrink-0" />
                {error}
              </div>
            )}

            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Email
              </label>
              <div className="relative">
                <Mail className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  type="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  className="w-full rounded-xl border border-[#dce5de] bg-[#f7f9f7] py-3 pl-10 pr-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#2d8f63] focus:bg-white focus:ring-2 focus:ring-[#dff3e7]"
                  placeholder="admin@erp.com"
                  required
                />
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-gray-700">
                Password
              </label>
              <div className="relative">
                <LockKeyhole className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                <input
                  type="password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  className="w-full rounded-xl border border-[#dce5de] bg-[#f7f9f7] py-3 pl-10 pr-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-[#2d8f63] focus:bg-white focus:ring-2 focus:ring-[#dff3e7]"
                  placeholder="Password"
                  required
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full rounded-xl bg-[#237a55] px-4 py-3 font-semibold text-white shadow-sm shadow-emerald-900/15 transition hover:bg-[#174a38] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {loading ? (
                <span className="flex items-center justify-center gap-2">
                  <LoaderCircle className="h-4 w-4 animate-spin" />
                  Signing in...
                </span>
              ) : (
                'Sign in'
              )}
            </button>
          </form>

          <div className="mt-6 border-t border-gray-100 pt-6">
            <p className="text-center text-xs text-gray-400">
              Demo:{' '}
              <span className="font-medium text-gray-600">admin@erp.com</span> /{' '}
              <span className="font-medium text-gray-600">password</span>
            </p>
          </div>
        </section>
      </main>
    </div>
  );
}
