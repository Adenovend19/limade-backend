<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                ];
            }),
            'invoice_number' => $this->invoice_number,
            'total_amount' => (float) $this->total_amount,
            'payment_method' => $this->payment_method,
            'payment_proof' => $this->payment_proof,
            'payment_notes' => $this->payment_notes,
            'rejection_reason' => $this->rejection_reason,
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approver' => $this->whenLoaded('approver', function () {
                return [
                    'id' => $this->approver->id,
                    'name' => $this->approver->name,
                    'email' => $this->approver->email,
                ];
            }),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
