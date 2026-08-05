<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';
    protected $fillable = ['Code', 'name', 'contact_person', 'phone', 'email', 'address', 'tax_number', 'credit_limit', 'payment_terms_days', 'active', 'BC', 'UID'];
    protected $casts = ['credit_limit' => 'decimal:2', 'active' => 'boolean'];
    
}
