<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
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
            'cart_id' => $this->cart_id,
            'package_id' => $this->package_id,
            'package' => new PackageResource($this->whenLoaded('package')),
            'merchant_id' => $this->merchant_id,
            'merchant' => new MerchantResource($this->whenLoaded('merchant')),
            'quantity' => $this->quantity,
            'price' => (float) $this->price,
            'subtotal' => $this->subtotal,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
