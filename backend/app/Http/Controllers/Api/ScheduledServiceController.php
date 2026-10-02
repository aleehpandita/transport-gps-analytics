<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduledServiceRequest;
use App\Http\Resources\ScheduledServiceResource;
use App\Models\ScheduledService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduledServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ScheduledServiceResource::collection(
            ScheduledService::with(['vehicle', 'destinationZone'])
                ->orderByDesc('scheduled_at')
                ->paginate(50)
        );
    }

    public function store(StoreScheduledServiceRequest $request): ScheduledServiceResource
    {
        $service = ScheduledService::create($request->validated());

        return new ScheduledServiceResource($service->load(['vehicle', 'destinationZone']));
    }
}