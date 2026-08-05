<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $table = 'companies';
    protected $fillable = [
        'name', 'address', 'phone', 'email', 'tax_number', 'currency', 'timezone',
        'receipt_footer', 'secondary_language', 'receipt_secondary_footer',
        'customer_credit_enabled', 'post_dated_cheques_enabled', 'accounting_enabled',
        'bilingual_receipts_enabled', 'scale_barcode_prefix', 'scale_product_digits',
        'scale_weight_digits', 'cash_drawer_enabled', 'cash_drawer_command',
        'label_printer_enabled', 'label_printer_name', 'receipt_printer_name',
    ];

    protected $casts = [
        'customer_credit_enabled' => 'boolean', 'post_dated_cheques_enabled' => 'boolean',
        'accounting_enabled' => 'boolean', 'bilingual_receipts_enabled' => 'boolean',
        'cash_drawer_enabled' => 'boolean', 'label_printer_enabled' => 'boolean',
    ];
    
    
}
