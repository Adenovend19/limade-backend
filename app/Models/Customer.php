<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'created_by',
        'name',
        'company_name',
        'email',
        'phone',
        'address',
        'stage',
        'source',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    public function assignedPackages(): HasMany
    {
        return $this->hasMany(CustomerAssignedPackage::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(CustomerVisit::class)->orderBy('scheduled_at', 'desc');
    }
}
