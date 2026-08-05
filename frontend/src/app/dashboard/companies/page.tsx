'use client';

import { Building2 } from 'lucide-react';
import { CrudWorkspace, type CrudRecord } from '@/components/crud-workspace';

interface CompanyRecord extends CrudRecord {
  name: string; address: string | null; phone: string | null; email: string | null;
  tax_number: string | null; currency: string; timezone: string;
  customer_credit_enabled: boolean; post_dated_cheques_enabled: boolean; accounting_enabled: boolean;
  bilingual_receipts_enabled: boolean; cash_drawer_enabled: boolean; label_printer_enabled: boolean;
}

const yesNo = [{ value: 'false', label: 'Disabled' }, { value: 'true', label: 'Enabled' }];
const initialValues = {
  name: '', phone: '', email: '', address: '', tax_number: '', currency: 'LKR', timezone: 'Asia/Colombo',
  receipt_footer: '', secondary_language: '', receipt_secondary_footer: '', customer_credit_enabled: 'false',
  post_dated_cheques_enabled: 'false', accounting_enabled: 'false', bilingual_receipts_enabled: 'false',
  scale_barcode_prefix: '', scale_product_digits: '5', scale_weight_digits: '5', cash_drawer_enabled: 'false',
  cash_drawer_command: '', label_printer_enabled: 'false', label_printer_name: '', receipt_printer_name: '',
};

export default function CompaniesPage() {
  return <CrudWorkspace<CompanyRecord>
    title="Company settings"
    description="Manage shop identity, tax, receipt, credit, accounting, barcode scale, and connected counter devices."
    endpoint="/v1/companies" module="settings" singular="Company" plural="Company settings" icon={Building2}
    initialValues={initialValues} searchKeys={['name','tax_number','currency']} addLabel="Add company"
    transformSubmit={(values) => ({
      ...values,
      customer_credit_enabled: values.customer_credit_enabled === 'true', post_dated_cheques_enabled: values.post_dated_cheques_enabled === 'true',
      accounting_enabled: values.accounting_enabled === 'true', bilingual_receipts_enabled: values.bilingual_receipts_enabled === 'true',
      cash_drawer_enabled: values.cash_drawer_enabled === 'true', label_printer_enabled: values.label_printer_enabled === 'true',
      scale_product_digits: Number(values.scale_product_digits), scale_weight_digits: Number(values.scale_weight_digits),
      secondary_language: values.secondary_language || null, scale_barcode_prefix: values.scale_barcode_prefix || null,
      cash_drawer_command: values.cash_drawer_command || null, label_printer_name: values.label_printer_name || null,
      receipt_printer_name: values.receipt_printer_name || null,
    })}
    fields={[
      {name:'name',label:'Shop / company name',required:true,span:2},{name:'tax_number',label:'Tax registration number',nullable:true},
      {name:'currency',label:'Currency code',required:true,maxLength:3},{name:'timezone',label:'Timezone',required:true},
      {name:'phone',label:'Phone',type:'tel',nullable:true},{name:'email',label:'Email',type:'email',nullable:true},
      {name:'address',label:'Address',type:'textarea',nullable:true,span:2},{name:'receipt_footer',label:'Receipt footer',type:'textarea',nullable:true,span:2},
      {name:'customer_credit_enabled',label:'Customer credit sales',type:'select',options:yesNo,required:true},
      {name:'post_dated_cheques_enabled',label:'Post-dated cheques',type:'select',options:yesNo,required:true},
      {name:'accounting_enabled',label:'Advanced chart of accounts',type:'select',options:yesNo,required:true},
      {name:'bilingual_receipts_enabled',label:'Bilingual receipts',type:'select',options:yesNo,required:true},
      {name:'secondary_language',label:'Second language',nullable:true},{name:'receipt_secondary_footer',label:'Second-language receipt footer',type:'textarea',nullable:true},
      {name:'scale_barcode_prefix',label:'Scale barcode prefix',nullable:true},{name:'scale_product_digits',label:'Scale product-code digits',type:'number',required:true,min:1,max:8},
      {name:'scale_weight_digits',label:'Scale weight digits',type:'number',required:true,min:1,max:8},
      {name:'receipt_printer_name',label:'Receipt printer name',nullable:true},{name:'cash_drawer_enabled',label:'Cash drawer',type:'select',options:yesNo,required:true},
      {name:'cash_drawer_command',label:'Cash drawer command',nullable:true},{name:'label_printer_enabled',label:'Label printer',type:'select',options:yesNo,required:true},
      {name:'label_printer_name',label:'Label printer name',nullable:true},
    ]}
    columns={[
      {key:'name',label:'Company',render:(record)=><span className="font-bold text-slate-900">{record.name}</span>},
      {key:'currency',label:'Currency'},{key:'timezone',label:'Timezone'},{key:'tax_number',label:'Tax number'},
      {key:'customer_credit_enabled',label:'Credit',render:(record)=>record.customer_credit_enabled?'Enabled':'Disabled'},
      {key:'accounting_enabled',label:'Accounting',render:(record)=>record.accounting_enabled?'Enabled':'Disabled'},
    ]}
  />;
}
