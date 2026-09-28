<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
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
            'business_type_id' => $this->business_type_id,
            'business_type' => new BusinessTypeResource($this->whenLoaded('businessType')),
            'name' => $this->name,
            'code' => $this->code,
            'price' => (float) $this->price,
            'duration_in_days' => $this->duration_in_days,
            'description' => $this->description,
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
