<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

class CompanyController extends BranchScopedCrudController
{
    protected function modelClass(): string
    {
        return Company::class;
    }

    protected function resourceName(): string
    {
        return 'company';
    }

    protected function branchScoped(): bool
    {
        return false;
    }

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => [$model ? 'sometimes' : 'required', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'phone' => $this->phoneRules(),
            'email' => ['nullable', 'email', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'currency' => [$model ? 'sometimes' : 'required', 'string', 'size:3'],
            'timezone' => [$model ? 'sometimes' : 'required', 'timezone'],
            'receipt_footer' => ['nullable', 'string', 'max:1000'],
            'secondary_language' => ['nullable', 'string', 'max:20'],
            'receipt_secondary_footer' => ['nullable', 'string', 'max:1000'],
            'customer_credit_enabled' => ['boolean'],
            'post_dated_cheques_enabled' => ['boolean'],
            'accounting_enabled' => ['boolean'],
            'bilingual_receipts_enabled' => ['boolean'],
            'scale_barcode_prefix' => ['nullable', 'string', 'max:8'],
            'scale_product_digits' => ['integer', 'between:1,8'],
            'scale_weight_digits' => ['integer', 'between:1,8'],
            'cash_drawer_enabled' => ['boolean'],
            'cash_drawer_command' => ['nullable', 'string', 'max:120'],
            'label_printer_enabled' => ['boolean'],
            'label_printer_name' => ['nullable', 'string', 'max:120'],
            'receipt_printer_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['name', 'email', 'phone'];
    }

    protected function defaultOrderColumn(): string
    {
        return 'name';
    }
}
