<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $role = $this->pivot?->role;
        if (! $role && $request->user()) {
            $role = $this->users()->where('users.id', $request->user()->id)->value('role');
        }

        $pendingOrder = null;
        if ($request->user()) {
            $pendingOrder = \App\Models\Order::where('user_id', $request->user()->id)
                ->where('status', 'pending')
                ->whereHas('items', function ($query) {
                    $query->where('merchant_id', $this->id);
                })
                ->with(['items.package'])
                ->latest()
                ->first();
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'role' => $role,
            'has_active_access' => $this->hasActiveAccess(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'subscription_expires_at' => $this->subscription_expires_at?->toIso8601String(),
            'business_type_id' => $this->business_type_id,
            'business_type' => new BusinessTypeResource($this->whenLoaded('businessType')),
            'pending_order' => $pendingOrder ? new OrderResource($pendingOrder) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
