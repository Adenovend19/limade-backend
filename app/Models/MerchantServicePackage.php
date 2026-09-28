<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantServicePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'name',
        'code',
        'price',
        'billing_period',
        'description',
        'is_active',
    ];

    protected $casts = [
        'price' => 'integer',
        'is_active' => 'boolean',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customerAssignments(): HasMany
    {
        return $this->hasMany(CustomerAssignedPackage::class, 'package_id');
    }
}
