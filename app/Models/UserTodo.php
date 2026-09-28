<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTodo extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'user_id',
        'title',
        'description',
        'due_date',
        'priority',
        'is_completed',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
