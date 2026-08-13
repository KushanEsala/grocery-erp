'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Pencil, Plus, ShieldCheck, Trash2, UserCog, UsersRound } from 'lucide-react';
import { useAuth } from '@/lib/auth-context';
import { api, getApiErrorMessage } from '@/lib/api';
import {
  LoadingTableRow,
  OperationActions,
  OperationField,
  OperationHeader,
  OperationModal,
  OperationNotice,
} from '@/components/operation-ui';

interface User {
  id: number;
  username: string;
  email: string;
  role_id: number;
  BC: string;
  role?: { id: number; name: string };
}

interface Role {
  id: number;
  name: string;
}

type UserForm = { username: string; email: string; password: string; role_id: string };
type Notice = { type: 'success' | 'error' | 'warning'; text: string };

const emptyForm: UserForm = { username: '', email: '', password: '', role_id: '' };
const inputClass = 'w-full rounded-xl border border-[#dce5de] bg-[#f7f9f7] px-3.5 py-2.5 text-sm outline-none transition focus:border-[#2d8f63] focus:bg-white focus:ring-2 focus:ring-[#dff3e7]';

export default function UsersPage() {
  const { user: authUser, isSuperAdmin, loading: authLoading } = useAuth();
  const router = useRouter();
  const [users, setUsers] = useState<User[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [modal, setModal] = useState<'create' | 'edit' | 'delete' | null>(null);
  const [selected, setSelected] = useState<User | null>(null);
  const [form, setForm] = useState<UserForm>(emptyForm);
  const [notice, setNotice] = useState<Notice | null>(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [userResponse, roleResponse] = await Promise.all([
        api.get<User[]>('/v1/users'),
        api.get<Role[]>('/v1/roles'),
      ]);
      setUsers(userResponse.data || []);
      setRoles(roleResponse.data || []);
    } catch (error: unknown) {
      setNotice({ type: 'error', text: getApiErrorMessage(error, 'Unable to load user accounts.') });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (authLoading) return;
    if (!isSuperAdmin) {
      router.replace('/dashboard');
      return;
    }
    const timer = window.setTimeout(() => void fetchData(), 0);
    return () => window.clearTimeout(timer);
  }, [authLoading, fetchData, isSuperAdmin, router]);

  const closeModal = () => {
    if (saving) return;
    setModal(null);
    setSelected(null);
    setForm(emptyForm);
  };

  const openCreate = () => {
    setNotice(null);
    setSelected(null);
    setForm(emptyForm);
    setModal('create');
  };

  const openEdit = (user: User) => {
    setNotice(null);
    setSelected(user);
    setForm({ username: user.username, email: user.email, password: '', role_id: String(user.role_id) });
    setModal('edit');
  };

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    try {
      const payload = { username: form.username.trim(), email: form.email.trim(), role_id: Number(form.role_id) };
      const response = modal === 'create'
        ? await api.post('/v1/users', { ...payload, password: form.password })
        : await api.put(`/v1/users/${selected?.id}`, payload);
      setModal(null);
      setSelected(null);
      setForm(emptyForm);
      await fetchData();
      setNotice({ type: 'success', text: response.message });
    } catch (error: unknown) {
      setNotice({ type: 'error', text: getApiErrorMessage(error, 'Unable to save the user account.') });
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (!selected) return;
    setSaving(true);
    try {
      const response = await api.delete(`/v1/users/${selected.id}`);
      setModal(null);
      setSelected(null);
      setForm(emptyForm);
      await fetchData();
      setNotice({ type: 'success', text: response.message });
    } catch (error: unknown) {
      setNotice({ type: 'error', text: getApiErrorMessage(error, 'Unable to delete the user account.') });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-6">
      <OperationHeader
        eyebrow="Administration"
        title="User accounts"
        description="Create staff access, update account details and safely revoke access without removing transaction history."
        icon={UsersRound}
        actions={<button type="button" onClick={openCreate} className="inline-flex items-center gap-2 rounded-xl bg-[#237a55] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#174a38]"><Plus className="h-4 w-4" />New user</button>}
      />

      {notice && !modal && <OperationNotice type={notice.type}>{notice.text}</OperationNotice>}

      <section className="overflow-hidden rounded-3xl border border-[#dce5de] bg-white shadow-sm">
        <div className="flex items-center justify-between border-b border-[#edf1ee] px-5 py-4">
          <div><h2 className="font-bold text-slate-950">Active accounts</h2><p className="mt-0.5 text-xs text-slate-500">{users.length} users in branch {authUser?.BC}</p></div>
          <ShieldCheck className="h-5 w-5 text-[#237a55]" />
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="bg-[#f7f9f7] text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">User</th><th className="px-5 py-3">Email</th><th className="px-5 py-3">Role</th><th className="px-5 py-3">Branch</th><th className="px-5 py-3 text-right">Actions</th></tr></thead>
            <tbody className="divide-y divide-[#edf1ee]">
              {loading ? <LoadingTableRow columns={5} label="Loading user accounts..." /> : users.length === 0 ? <tr><td colSpan={5} className="px-5 py-14 text-center text-slate-400">No active user accounts.</td></tr> : users.map((user) => (
                <tr key={user.id} className="transition hover:bg-[#fbfcfb]">
                  <td className="px-5 py-3.5"><div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-xl bg-[#eff9f2] font-bold text-[#237a55]">{user.username.slice(0, 1).toUpperCase()}</span><div><p className="font-semibold text-slate-900">{user.username}</p>{user.id === authUser?.id && <p className="text-[10px] font-bold uppercase tracking-wide text-[#237a55]">Your account</p>}</div></div></td>
                  <td className="px-5 py-3.5 text-slate-600">{user.email}</td>
                  <td className="px-5 py-3.5"><span className="rounded-full bg-[#eff9f2] px-2.5 py-1 text-xs font-semibold text-[#174a38] ring-1 ring-inset ring-[#c5e8d2]">{user.role?.name || 'No role'}</span></td>
                  <td className="px-5 py-3.5 font-mono text-xs text-slate-500">{user.BC}</td>
                  <td className="px-5 py-3.5"><div className="flex justify-end gap-2"><button type="button" onClick={() => openEdit(user)} aria-label={`Edit ${user.username}`} className="rounded-lg border border-[#dce5de] p-2 text-slate-500 transition hover:border-[#b9d7c5] hover:bg-[#eff9f2] hover:text-[#237a55]"><Pencil className="h-4 w-4" /></button><button type="button" onClick={() => { setSelected(user); setModal('delete'); setNotice(null); }} disabled={user.id === authUser?.id} aria-label={`Delete ${user.username}`} title={user.id === authUser?.id ? 'You cannot delete your own account' : 'Delete account'} className="rounded-lg border border-rose-200 p-2 text-rose-600 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-35"><Trash2 className="h-4 w-4" /></button></div></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {(modal === 'create' || modal === 'edit') && (
        <OperationModal title={modal === 'create' ? 'Create user account' : `Edit ${selected?.username}`} description={modal === 'create' ? `The account will belong to branch ${authUser?.BC}.` : 'Changes apply the next time permissions are checked.'} onClose={closeModal} width="max-w-xl">
          <form onSubmit={save} className="space-y-4 p-6">
            {notice && <OperationNotice type={notice.type}>{notice.text}</OperationNotice>}
            <div className="grid gap-4 sm:grid-cols-2"><OperationField label="Username" required><input value={form.username} onChange={(event) => setForm((current) => ({ ...current, username: event.target.value }))} maxLength={50} className={inputClass} required /></OperationField><OperationField label="Email" required><input type="email" value={form.email} onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))} maxLength={100} className={inputClass} required /></OperationField></div>
            {modal === 'create' && <OperationField label="Temporary password" required help="The user can replace this from My Account after signing in."><input type="password" minLength={8} autoComplete="new-password" value={form.password} onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))} className={inputClass} required /></OperationField>}
            <OperationField label="Role" required help={selected?.id === authUser?.id ? 'Your own role cannot be changed while you are signed in.' : undefined}><select value={form.role_id} onChange={(event) => setForm((current) => ({ ...current, role_id: event.target.value }))} disabled={selected?.id === authUser?.id} className={`${inputClass} disabled:cursor-not-allowed disabled:opacity-60`} required><option value="">Select a role</option>{roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}</select></OperationField>
            <OperationActions saving={saving} submitLabel={modal === 'create' ? 'Create user' : 'Save changes'} onCancel={closeModal} disabled={!form.username.trim() || !form.email.trim() || !form.role_id || (modal === 'create' && form.password.length < 8)} />
          </form>
        </OperationModal>
      )}

      {modal === 'delete' && selected && (
        <OperationModal title="Delete user account?" description="This action immediately revokes access." onClose={closeModal} width="max-w-lg">
          <div className="space-y-4 p-6">{notice && <OperationNotice type={notice.type}>{notice.text}</OperationNotice>}<div className="rounded-2xl border border-rose-200 bg-rose-50 p-4"><div className="flex gap-3"><UserCog className="mt-0.5 h-5 w-5 shrink-0 text-rose-600" /><div><p className="font-bold text-rose-900">{selected.username}</p><p className="mt-1 text-sm leading-6 text-rose-700">The account will no longer be able to sign in. Sales, stock movements and audit history linked to this user will be preserved.</p></div></div></div><div className="flex justify-end gap-3"><button type="button" onClick={closeModal} disabled={saving} className="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</button><button type="button" onClick={() => void remove()} disabled={saving} className="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-rose-700 disabled:opacity-50"><Trash2 className="h-4 w-4" />{saving ? 'Deleting...' : 'Delete account'}</button></div></div>
        </OperationModal>
      )}
    </div>
  );
}
