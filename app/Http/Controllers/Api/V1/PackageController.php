<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PackageController extends Controller
{
    /**
     * Display a listing of packages with optional filtering by business type.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Package::with('businessType')->where('is_active', true);

        if ($request->has('business_type_id')) {
            $query->where('business_type_id', $request->input('business_type_id'));
        }

        if ($request->has('business_type_code')) {
            $code = $request->input('business_type_code');
            $query->whereHas('businessType', function ($q) use ($code) {
                $q->where('code', $code);
            });
        }

        $packages = $query->orderBy('price', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => PackageResource::collection($packages),
        ], Response::HTTP_OK);
    }

    /**
     * Display the specified package.
     */
    public function show(Package $package): JsonResponse
    {
        $package->load('businessType');

        return response()->json([
            'success' => true,
            'data' => new PackageResource($package),
        ], Response::HTTP_OK);
    }
}
