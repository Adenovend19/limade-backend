<?php

namespace App\Models;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    protected $fillable = [
        'business_type_id',
        'name',
        'slug',
        'trial_ends_at',
        'subscription_expires_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'subscription_expires_at' => 'datetime',
        ];
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'merchant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function billingInvoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    /**
     * Check if merchant has an active trial or paid subscription.
     */
    public function hasActiveAccess(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->subscription_expires_at && $this->subscription_expires_at->isFuture()) {
            return true;
        }

        if ($this->trial_ends_at && $this->trial_ends_at->isFuture()) {
            return true;
        }

        return false;
    }

    /**
     * Extend subscription cumulatively.
     */
    public function extendSubscription(int $days): void
    {
        $baseDate = now();

        if ($this->subscription_expires_at && $this->subscription_expires_at->isFuture()) {
            $baseDate = $this->subscription_expires_at->copy();
        }

        $this->subscription_expires_at = $baseDate->addDays($days);
        $this->status = 'active';

        if (! $this->trial_ends_at) {
            $this->trial_ends_at = now()->addDays(14);
        }

        $this->save();
    }
}
