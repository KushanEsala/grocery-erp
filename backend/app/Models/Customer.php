<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';
    protected $fillable = ['Code', 'name', 'NIC', 'phone', 'email', 'address', 'tax_number', 'loyalty_number', 'credit_limit', 'advance_balance', 'active', 'BC', 'UID'];
    public $timestamps = false;

    protected $casts = [
        'advance_balance' => 'decimal:2',
        'credit_limit' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function invoices()
    {
        return $this->hasMany(TInvoiceSum::class, 'customer_code', 'Code');
    }

    public function advances()
    {
        return $this->hasMany(TAdvancCusPayment::class, 'customer_nic', 'NIC');
    }

    public function payments()
    {
        return $this->hasMany(TCustomerPayment::class, 'Customer_NIC', 'NIC');
    }
}
