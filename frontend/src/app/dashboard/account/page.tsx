'use client';

import { useState } from 'react';
import { KeyRound, LockKeyhole, ShieldCheck } from 'lucide-react';
import { useAuth } from '@/lib/auth-context';
import { api, getApiErrorMessage } from '@/lib/api';
import {
  OperationActions,
  OperationField,
  OperationHeader,
  OperationNotice,
} from '@/components/operation-ui';

const inputClass =
  'w-full rounded-xl border border-[#dce5de] bg-[#f7f9f7] px-3.5 py-3 text-sm outline-none transition focus:border-[#2d8f63] focus:bg-white focus:ring-2 focus:ring-[#dff3e7]';

export default function AccountPage() {
  const { user } = useAuth();
  const [form, setForm] = useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  });
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const reset = () => setForm({ current_password: '', password: '', password_confirmation: '' });

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setNotice(null);
    setSaving(true);
    try {
      const response = await api.put('/v1/user/password', form);
      reset();
      setNotice({ type: 'success', text: response.message });
    } catch (error: unknown) {
      setNotice({ type: 'error', text: getApiErrorMessage(error, 'Unable to change your password.') });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <OperationHeader
        eyebrow="Personal security"
        title="My account"
        description="Review your assigned access and protect your sign-in password."
        icon={KeyRound}
      />

      {notice && <OperationNotice type={notice.type}>{notice.text}</OperationNotice>}

      <div className="grid gap-6 lg:grid-cols-[0.8fr_1.2fr]">
        <section className="rounded-3xl border border-[#dce5de] bg-[#12382b] p-6 text-white shadow-sm">
          <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15">
            <ShieldCheck className="h-6 w-6 text-[#e3a32b]" />
          </div>
          <p className="mt-6 text-xs font-bold uppercase tracking-[0.16em] text-emerald-100/60">Signed in as</p>
          <h2 className="mt-2 text-xl font-bold">{user?.username}</h2>
          <p className="mt-1 break-all text-sm text-emerald-50/70">{user?.email}</p>
          <dl className="mt-6 grid grid-cols-2 gap-3 border-t border-white/10 pt-5 text-xs">
            <div><dt className="text-emerald-100/55">Role</dt><dd className="mt-1 font-semibold text-white">{user?.role?.name}</dd></div>
            <div><dt className="text-emerald-100/55">Branch</dt><dd className="mt-1 font-semibold text-white">{user?.BC}</dd></div>
          </dl>
        </section>

        <section className="rounded-3xl border border-[#dce5de] bg-white p-6 shadow-sm sm:p-7">
          <div className="flex items-start gap-3 border-b border-[#edf1ee] pb-5">
            <span className="rounded-xl bg-[#eff9f2] p-2.5 text-[#237a55]"><LockKeyhole className="h-5 w-5" /></span>
            <div><h2 className="font-bold text-slate-950">Change password</h2><p className="mt-1 text-xs leading-5 text-slate-500">Confirm your current password. Other signed-in devices will be logged out.</p></div>
          </div>
          <form onSubmit={submit} className="mt-6 space-y-4">
            <OperationField label="Current password" required>
              <input type="password" autoComplete="current-password" value={form.current_password} onChange={(event) => setForm((current) => ({ ...current, current_password: event.target.value }))} className={inputClass} required />
            </OperationField>
            <div className="grid gap-4 sm:grid-cols-2">
              <OperationField label="New password" required help="Use at least 8 characters.">
                <input type="password" minLength={8} autoComplete="new-password" value={form.password} onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))} className={inputClass} required />
              </OperationField>
              <OperationField label="Confirm new password" required>
                <input type="password" minLength={8} autoComplete="new-password" value={form.password_confirmation} onChange={(event) => setForm((current) => ({ ...current, password_confirmation: event.target.value }))} className={inputClass} required />
              </OperationField>
            </div>
            <OperationActions saving={saving} submitLabel="Change password" onCancel={reset} disabled={!form.current_password || form.password.length < 8 || form.password !== form.password_confirmation} />
          </form>
        </section>
      </div>
    </div>
  );
}
