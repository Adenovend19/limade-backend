<?php

namespace App\Models;

use Database\Factories\BusinessTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessType extends Model
{
    /** @use HasFactory<BusinessTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(BusinessTypeRole::class);
    }
}
