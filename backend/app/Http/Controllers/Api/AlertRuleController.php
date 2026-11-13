<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Models\Geofence;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Las alertas que configura cada empresa.
 *
 *   GET    /api/alert-rules
 *   POST   /api/alert-rules           {name, type, settings, vehicle_ids, days, time_from, time_to,
 *                                     repeat_minutes, emails, active}
 *   PATCH  /api/alert-rules/{rule}    lo mismo
 *   DELETE /api/alert-rules/{rule}
 *
 * settings según el tipo:
 *   overspeed       speed_kmh (20-250), seconds (0-600)
 *   geofence_*      geofence_id
 *   long_stop       minutes (5-1440), except_in_geofences (bool)
 *   signal_lost     minutes (5-1440)
 *   after_hours     nada; requiere horario
 */
class AlertRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rules = AlertRule::where('company_id', $this->companyId($request))->orderBy('name')->get();

        return response()->json([
            'data' => $rules->map->toApi()->values(),
            'types' => collect(AlertRule::LABELS)->map(fn ($label, $type) => ['type' => $type, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->canEdit(), 403, 'Tu usuario es de solo consulta.');

        $rule = AlertRule::create($this->validated($request) + ['company_id' => $this->companyId($request)]);

        return response()->json(['data' => $rule->toApi()], 201);
    }

    public function update(Request $request, AlertRule $alertRule): JsonResponse
    {
        $this->authorizeRule($request, $alertRule);

        $alertRule->update($this->validated($request, $alertRule));

        return response()->json(['data' => $alertRule->fresh()->toApi()]);
    }

    public function destroy(Request $request, AlertRule $alertRule): JsonResponse
    {
        $this->authorizeRule($request, $alertRule);

        // Los avisos que ya salieron se quedan en la bandeja (alert_rule_id queda en null)
        $alertRule->delete();

        return response()->json(['message' => 'Alerta eliminada.']);
    }

    /**
     * Valida y normaliza. Al editar se manda la regla completa (el formulario siempre
     * tiene todos los campos), así que se valida igual que al crear.
     */
    protected function validated(Request $request, ?AlertRule $current = null): array
    {
        $companyId = $this->companyId($request);

        // Un cambio de solo "activa / pausada" desde la lista
        if ($current && $request->keys() === ['active']) {
            return $request->validate(['active' => ['required', 'boolean']]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(AlertRule::TYPES)],
            'settings' => ['nullable', 'array'],
            'vehicle_ids' => ['nullable', 'array'],
            'vehicle_ids.*' => ['integer'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'time_from' => ['nullable', 'date_format:H:i', 'required_with:time_to'],
            'time_to' => ['nullable', 'date_format:H:i', 'required_with:time_from', 'different:time_from'],
            'repeat_minutes' => ['required', 'integer', Rule::in(AlertRule::REPEAT_OPTIONS)],
            'emails' => ['nullable', 'array', 'max:10'],
            'emails.*' => ['email', 'max:190'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'days.required' => 'Elige al menos un día.',
            'days.min' => 'Elige al menos un día.',
            'emails.*.email' => 'Revisa los correos: :input no es válido.',
            'time_to.different' => 'El horario debe terminar a otra hora de la que empieza.',
        ]);

        $data['settings'] = $this->settings($data['type'], $data['settings'] ?? [], $companyId);

        if ($data['type'] === AlertRule::AFTER_HOURS && empty($data['time_from'])) {
            throw ValidationException::withMessages(['time_from' => 'Indica el horario en que la unidad no debe moverse.']);
        }

        // Solo unidades de la empresa. Vacío = todas.
        $vehicleIds = array_values(array_unique(array_map('intval', $data['vehicle_ids'] ?? [])));
        if ($vehicleIds) {
            $own = Vehicle::where('company_id', $companyId)->whereIn('id', $vehicleIds)->count();
            if ($own !== count($vehicleIds)) {
                throw ValidationException::withMessages(['vehicle_ids' => 'Alguna unidad no es de tu empresa.']);
            }
        }
        $data['vehicle_ids'] = $vehicleIds ?: null;

        $data['days'] = array_values(array_unique(array_map('intval', $data['days'])));
        sort($data['days']);
        $data['emails'] = array_values(array_unique(array_map(fn ($e) => strtolower(trim($e)), $data['emails'] ?? []))) ?: null;

        return $data;
    }

    protected function settings(string $type, array $input, int $companyId): array
    {
        $int = fn (string $key, int $min, int $max, int $default) => $this->intSetting($input, $key, $min, $max, $default);

        return match ($type) {
            AlertRule::OVERSPEED => [
                'speed_kmh' => $int('speed_kmh', 20, 250, 100),
                'seconds' => $int('seconds', 0, 600, 30),
            ],
            AlertRule::GEOFENCE_EXIT, AlertRule::GEOFENCE_ENTER => [
                'geofence_id' => $this->geofenceSetting($input, $companyId),
            ],
            AlertRule::LONG_STOP => [
                'minutes' => $int('minutes', 5, 1440, 30),
                'except_in_geofences' => (bool) ($input['except_in_geofences'] ?? false),
            ],
            AlertRule::SIGNAL_LOST => [
                'minutes' => $int('minutes', 5, 1440, 15),
            ],
            default => [],
        };
    }

    protected function intSetting(array $input, string $key, int $min, int $max, int $default): int
    {
        if (!isset($input[$key]) || $input[$key] === '') {
            return $default;
        }

        $value = filter_var($input[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw ValidationException::withMessages(["settings.{$key}" => "Debe ser un número entre {$min} y {$max}."]);
        }

        return $value;
    }

    protected function geofenceSetting(array $input, int $companyId): int
    {
        $id = (int) ($input['geofence_id'] ?? 0);
        if (!$id || !Geofence::where('company_id', $companyId)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['settings.geofence_id' => 'Elige una de tus geocercas.']);
        }

        return $id;
    }

    protected function authorizeRule(Request $request, AlertRule $rule): void
    {
        abort_unless($rule->company_id === $this->companyId($request), 404);
        abort_unless($request->user()->canEdit(), 403, 'Tu usuario es de solo consulta.');
    }

    protected function companyId(Request $request): int
    {
        return (int) $request->attributes->get('company_id');
    }
}
