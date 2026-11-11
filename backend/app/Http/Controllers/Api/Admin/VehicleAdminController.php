<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Vehicle;
use App\Services\AccountService;
use App\Services\TraccarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Unidades desde el panel de EkMira.
 *
 *   GET   /api/admin/vehicles?unassigned=1         unidades sin empresa (para asignarlas)
 *   POST  /api/admin/companies/{company}/vehicles  {vehicle_id} asigna una que ya existe, o
 *                                                  {name, imei, plate?} da de alta un equipo nuevo en Traccar
 *   PATCH /api/admin/vehicles/{vehicle}            nombre, placas, marca, modelo, activa o empresa
 */
class VehicleAdminController extends Controller
{
    public function __construct(protected AccountService $accounts)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $vehicles = Vehicle::with('latestPosition')
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('company_id'))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $vehicles->map(fn ($v) => $this->accounts->vehicleRow($v))->values()]);
    }

    public function store(Request $request, Company $company, TraccarService $traccar): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['nullable', 'integer', Rule::exists('vehicles', 'id')],
            'name' => ['required_without:vehicle_id', 'nullable', 'string', 'max:80'],
            // IMEI del FMC920: 15 dígitos
            'imei' => ['required_without:vehicle_id', 'nullable', 'digits:15'],
            'plate' => ['nullable', 'string', 'max:20'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
        ]);

        if (!empty($validated['vehicle_id'])) {
            $vehicle = Vehicle::findOrFail($validated['vehicle_id']);
            if ($vehicle->company_id !== null && $vehicle->company_id !== $company->id) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Esa unidad ya es de otra empresa. Quítasela primero.',
                ]);
            }
            $vehicle->update(['company_id' => $company->id]);
        } else {
            $vehicle = $this->createWithDevice($company, $validated, $traccar);
        }

        return response()->json(['data' => $this->accounts->vehicleRow($vehicle->load('latestPosition'))], 201);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'plate' => ['nullable', 'string', 'max:20'],
            'make' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'active' => ['sometimes', 'boolean'],
            'company_id' => ['sometimes', 'nullable', 'integer', Rule::exists('companies', 'id')],
        ]);

        $vehicle->update($validated);

        return response()->json(['data' => $this->accounts->vehicleRow($vehicle->load('latestPosition'))]);
    }

    protected function createWithDevice(Company $company, array $data, TraccarService $traccar): Vehicle
    {
        $existing = Vehicle::firstWhere('imei', $data['imei']);
        if ($existing && $existing->company_id !== null && $existing->company_id !== $company->id) {
            throw ValidationException::withMessages(['imei' => 'Ese IMEI ya es de una unidad de otra empresa.']);
        }

        try {
            $device = $traccar->createDevice($data['name'], $data['imei']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['imei' => 'No se pudo registrar en Traccar: ' . $e->getMessage()]);
        }

        // Si traccar:sync-devices ya la había creado, se completa esa misma unidad
        $vehicle = $existing ?? Vehicle::firstWhere('traccar_device_id', $device['id']) ?? new Vehicle();
        $vehicle->fill([
            'company_id' => $company->id,
            'name' => $data['name'],
            'imei' => $data['imei'],
            'traccar_device_id' => $device['id'],
            'plate' => $data['plate'] ?? $vehicle->plate,
            'make' => $data['make'] ?? $vehicle->make,
            'model' => $data['model'] ?? $vehicle->model,
            'active' => true,
        ])->save();

        return $vehicle;
    }
}
