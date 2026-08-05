<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class CustomerController extends GeneratedCodeCrudController
{
    protected function modelClass(): string
    {
        return Customer::class;
    }

    protected function resourceName(): string
    {
        return 'customer';
    }

    protected function codeField(): string
    {
        return 'Code';
    }

    protected function codePrefix(): string
    {
        return 'CUST';
    }

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => [$model ? 'sometimes' : 'required', 'string', 'max:100'],
            'NIC' => [
                ...$this->nicRules($model === null),
                Rule::unique('customers', 'NIC')->where(fn ($query) => $query->where('BC', $this->branchCode()))->ignore($model?->getKey()),
            ],
            'phone' => $this->phoneRules(),
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'loyalty_number' => ['nullable', 'string', 'max:80'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['Code', 'name', 'NIC', 'phone'];
    }

    protected function defaultOrderColumn(): string
    {
        return 'Code';
    }

    protected function defaultOrderDirection(): string
    {
        return 'asc';
    }
}
