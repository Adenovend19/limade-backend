<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Models\BusinessTypeRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class BusinessTypeRoleController extends Controller
{
    /**
     * List all operational roles for a specific business type.
     */
    public function index(BusinessType $businessType): JsonResponse
    {
        $roles = $businessType->roles()->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'business_type' => [
                    'id' => $businessType->id,
                    'name' => $businessType->name,
                    'code' => $businessType->code,
                ],
                'roles' => $roles,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Create a new role for a business type (Platform / Limade Staff).
     */
    public function store(Request $request, BusinessType $businessType): JsonResponse
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('business_type_roles', 'code')->where('business_type_id', $businessType->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $role = $businessType->roles()->create($validated);

        return response()->json([
            'success' => true,
            'message' => "Role '{$role->name}' created successfully for business type '{$businessType->name}'.",
            'data' => $role,
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an existing role for a business type.
     */
    public function update(Request $request, BusinessType $businessType, BusinessTypeRole $role): JsonResponse
    {
        if ($role->business_type_id !== $businessType->id) {
            return response()->json([
                'success' => false,
                'message' => 'Role does not belong to this business type.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $role->update($validated);

        return response()->json([
            'success' => true,
            'message' => "Role '{$role->name}' updated successfully.",
            'data' => $role,
        ], Response::HTTP_OK);
    }

    /**
     * Delete a role from a business type.
     */
    public function destroy(BusinessType $businessType, BusinessTypeRole $role): JsonResponse
    {
        if ($role->business_type_id !== $businessType->id) {
            return response()->json([
                'success' => false,
                'message' => 'Role does not belong to this business type.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $roleName = $role->name;
        $role->delete();

        return response()->json([
            'success' => true,
            'message' => "Role '{$roleName}' deleted successfully.",
        ], Response::HTTP_OK);
    }
}
