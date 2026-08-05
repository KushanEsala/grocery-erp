<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

class SupplierController extends GeneratedCodeCrudController
{
    protected function modelClass(): string
    {
        return Supplier::class;
    }

    protected function resourceName(): string
    {
        return 'supplier';
    }

    protected function codeField(): string
    {
        return 'Code';
    }

    protected function codePrefix(): string
    {
        return 'SUP';
    }

    protected function rules(?Model $model = null): array
    {
        return [
            'name' => [$model ? 'sometimes' : 'required', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => $this->phoneRules(),
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    protected function searchableColumns(): array
    {
        return ['Code', 'name', 'contact_person', 'phone', 'email'];
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
