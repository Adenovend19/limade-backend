<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessTypeResource;
use App\Models\BusinessType;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class BusinessTypeController extends Controller
{
    /**
     * Display a listing of active business types.
     */
    public function index(): JsonResponse
    {
        $types = BusinessType::with('roles')->where('is_active', true)->get();

        return response()->json([
            'success' => true,
            'data' => BusinessTypeResource::collection($types),
        ], Response::HTTP_OK);
    }

    /**
     * Display the specified business type.
     */
    public function show(BusinessType $businessType): JsonResponse
    {
        $businessType->load('roles');

        return response()->json([
            'success' => true,
            'data' => new BusinessTypeResource($businessType),
        ], Response::HTTP_OK);
    }
}
