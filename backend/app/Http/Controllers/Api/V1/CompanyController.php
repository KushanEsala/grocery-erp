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
