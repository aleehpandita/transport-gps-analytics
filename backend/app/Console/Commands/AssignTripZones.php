<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Models\Trip;
use App\Services\ZoneMatchingService;
use Illuminate\Console\Command;

class AssignTripZones extends Command
{
    protected $signature = 'trips:assign-zones';
    protected $description = 'Rellena origin_zone_id/destination_zone_id en trips existentes que no lo tengan';

    public function handle(ZoneMatchingService $matcher): int
    {
        $trips = Trip::whereNull('origin_zone_id')->orWhereNull('destination_zone_id')->get();

        foreach ($trips as $trip) {
            $origin = Position::where('vehicle_id', $trip->vehicle_id)
                ->where('device_time', $trip->started_at)->first();
            $destination = Position::where('vehicle_id', $trip->vehicle_id)
                ->where('device_time', $trip->ended_at)->first();

            $originZone = $origin ? $matcher->resolve($origin->latitude, $origin->longitude) : null;
            $destinationZone = $destination ? $matcher->resolve($destination->latitude, $destination->longitude) : null;

            $trip->update([
                'origin_zone_id' => $originZone?->id,
                'destination_zone_id' => $destinationZone?->id,
            ]);

            $this->info("Trip {$trip->id}: " . ($originZone?->name ?? '?') . " -> " . ($destinationZone?->name ?? '?'));
        }

        return self::SUCCESS;
    }
}