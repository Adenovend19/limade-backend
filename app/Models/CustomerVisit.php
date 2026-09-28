<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'customer_id',
        'assigned_staff_id',
        'created_by',
        'title',
        'visit_type',
        'scheduled_at',
        'departed_at',
        'status',
        'address',
        'notes',
        'result_notes',
        'completed_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'departed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $appends = [
        'duration_in_minutes',
    ];

    public function getDurationInMinutesAttribute(): ?int
    {
        if ($this->departed_at && $this->completed_at) {
            return (int) round($this->departed_at->diffInMinutes($this->completed_at));
        }

        return null;
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
