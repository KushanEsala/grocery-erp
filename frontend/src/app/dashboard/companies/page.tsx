'use client';

import { BriefcaseBusiness } from 'lucide-react';
import { CrudWorkspace, type CrudRecord } from '@/components/crud-workspace';

interface CompanyRecord extends CrudRecord {
  name: string;
  address: string | null;
  phone: string | null;
  email: string | null;
}

export default function CompaniesPage() {
  return (
    <CrudWorkspace<CompanyRecord>
      title="Companies"
      description="Maintain the legal company identities managed by this ERP installation."
      endpoint="/v1/companies"
      module="companies"
      singular="Company"
      plural="Companies"
      icon={BriefcaseBusiness}
      initialValues={{ name: '', phone: '', email: '', address: '' }}
      searchKeys={['name', 'phone', 'email']}
      fields={[
        {
          name: 'name',
          label: 'Company name',
          required: true,
          placeholder: 'Registered company name',
          span: 2,
        },
        {
          name: 'phone',
          label: 'Phone',
          type: 'tel',
          nullable: true,
          maxLength: 25,
          pattern: '[0-9+()\\-\\s]{8,25}',
        },
        {
          name: 'email',
          label: 'Email',
          type: 'email',
          nullable: true,
          maxLength: 100,
        },
        {
          name: 'address',
          label: 'Address',
          type: 'textarea',
          nullable: true,
          span: 2,
        },
      ]}
      columns={[
        {
          key: 'name',
          label: 'Company',
          render: (record) => (
            <span className="font-semibold text-slate-900">{record.name}</span>
          ),
        },
        { key: 'phone', label: 'Phone' },
        { key: 'email', label: 'Email' },
      ]}
    />
  );
}
