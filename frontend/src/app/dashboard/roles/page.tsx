'use client';

import { useCallback, useEffect, useState } from 'react';
import { ShieldCheck } from 'lucide-react';
import { api, getApiErrorMessage } from '@/lib/api';
import { Permission } from '@/lib/auth-types';
import { OperationHeader, OperationNotice } from '@/components/operation-ui';

type Role = { id: number; name: string; description?: string; users_count: number };
const MODULES = ['dashboard','pos','products','categories','brands','units','taxes','suppliers','customers','stores','registers','purchases','purchase-returns','inventory','transfers','adjustments','stock-counts','sales','sales-returns','shifts','cash','expenses','supplier-payments','promotions','reports','audit','settings','backups','users','roles'];
const ACTIONS: Array<keyof Pick<Permission, 'can_read' | 'can_create' | 'can_update' | 'can_delete'>> = ['can_read','can_create','can_update','can_delete'];

export default function RolesPage() {
  const [roles, setRoles] = useState<Role[]>([]);
  const [roleId, setRoleId] = useState(0);
  const [permissions, setPermissions] = useState<Record<string, Permission>>({});
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{type:'success'|'error';text:string}|null>(null);

  useEffect(() => { void api.get<Role[]>('/v1/roles').then((response) => { const next = response.data || []; setRoles(next); setRoleId(next.find((role) => role.name !== 'Super Admin')?.id || 0); }); }, []);
  const load = useCallback(async () => {
    if (!roleId) return;
    try { const response = await api.get<Permission[]>(`/v1/permissions?role_id=${roleId}`); setPermissions(Object.fromEntries((response.data || []).map((permission) => [permission.module, permission]))); }
    catch (error) { setNotice({type:'error',text:getApiErrorMessage(error)}); }
  }, [roleId]);
  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer); }, [load]);

  function toggle(module: string, action: typeof ACTIONS[number]) {
    setPermissions((current) => ({ ...current, [module]: { id: current[module]?.id || 0, role_id: roleId, module, can_read: current[module]?.can_read || false, can_create: current[module]?.can_create || false, can_update: current[module]?.can_update || false, can_delete: current[module]?.can_delete || false, [action]: !current[module]?.[action] } }));
  }
  async function save() {
    setSaving(true); setNotice(null);
    try {
      await api.put(`/v1/permissions/${roleId}`, { permissions: MODULES.map((module) => ({ module, can_read: permissions[module]?.can_read || false, can_create: permissions[module]?.can_create || false, can_update: permissions[module]?.can_update || false, can_delete: permissions[module]?.can_delete || false })) });
      setNotice({type:'success',text:'Grocery module permissions saved.'}); await load();
    } catch (error) { setNotice({type:'error',text:getApiErrorMessage(error)}); }
    finally { setSaving(false); }
  }

  return <div className="space-y-6"><OperationHeader eyebrow="Administration" title="Roles and permissions" description="Control access only to Grocery ERP modules and actions." icon={ShieldCheck} actions={<button onClick={() => void save()} disabled={!roleId || saving} className="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-40">{saving?'Saving...':'Save permissions'}</button>} />{notice&&<OperationNotice type={notice.type}>{notice.text}</OperationNotice>}<section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm"><div className="border-b border-slate-200 p-4"><select value={roleId} onChange={(event)=>setRoleId(Number(event.target.value))} className="w-full max-w-sm rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold outline-none focus:border-emerald-500">{roles.filter((role)=>role.name!=='Super Admin').map((role)=><option key={role.id} value={role.id}>{role.name} ({role.users_count} users)</option>)}</select></div><div className="overflow-x-auto"><table className="min-w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th className="px-5 py-3">Grocery module</th>{ACTIONS.map((action)=><th key={action} className="px-5 py-3 text-center">{action.replace('can_','')}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{MODULES.map((module)=><tr key={module}><td className="px-5 py-3 font-bold capitalize text-slate-800">{module.replaceAll('-',' ')}</td>{ACTIONS.map((action)=><td key={action} className="px-5 py-3 text-center"><input aria-label={`${module} ${action}`} type="checkbox" checked={permissions[module]?.[action] || false} onChange={()=>toggle(module,action)} className="h-4 w-4 accent-emerald-600" /></td>)}</tr>)}</tbody></table></div></section></div>;
}
