<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'package_id' => $this->package_id,
            'package_name' => $this->package_name,
            'duration_in_days' => $this->duration_in_days,
            'merchant_id' => $this->merchant_id,
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
            'price' => (float) $this->price,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
