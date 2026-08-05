'use client';

import { Truck } from 'lucide-react';
import { CrudWorkspace, type CrudRecord } from '@/components/crud-workspace';

interface SupplierRecord extends CrudRecord {
  Code: string;
  name: string;
  phone: string | null;
  address: string | null;
}

export default function SuppliersPage() {
  return (
    <CrudWorkspace<SupplierRecord>
      title="Suppliers"
      description="Maintain grocery suppliers, contacts, payment terms, and tax details used by purchasing."
      endpoint="/v1/suppliers"
      module="suppliers"
      singular="Supplier"
      plural="Suppliers"
      icon={Truck}
      initialValues={{ name: '', contact_person: '', phone: '', email: '', address: '', tax_number: '', credit_limit: '0', payment_terms_days: '0' }}
      searchKeys={['Code', 'name', 'contact_person', 'phone', 'email']}
      fields={[
        {
          name: 'name',
          label: 'Supplier name',
          required: true,
        },
        { name: 'contact_person', label: 'Contact person', nullable: true },
        {
          name: 'phone',
          label: 'Phone',
          type: 'tel',
          nullable: true,
          maxLength: 25,
          pattern: '[0-9+()\\-\\s]{8,25}',
        },
        {
          name: 'address',
          label: 'Address',
          type: 'textarea',
          nullable: true,
          span: 2,
        },
        { name: 'email', label: 'Email', type: 'email', nullable: true },
        { name: 'tax_number', label: 'Tax number', nullable: true },
        { name: 'credit_limit', label: 'Credit limit', type: 'number', min: 0 },
        { name: 'payment_terms_days', label: 'Payment terms (days)', type: 'number', min: 0 },
      ]}
      columns={[
        {
          key: 'Code',
          label: 'Code',
          render: (record) => (
            <span className="font-mono text-xs font-semibold text-slate-700">
              {record.Code}
            </span>
          ),
        },
        {
          key: 'name',
          label: 'Supplier',
          render: (record) => (
            <span className="font-semibold text-slate-900">{record.name}</span>
          ),
        },
        { key: 'phone', label: 'Phone' },
        { key: 'contact_person', label: 'Contact' },
        { key: 'payment_terms_days', label: 'Terms (days)' },
      ]}
    />
  );
}
