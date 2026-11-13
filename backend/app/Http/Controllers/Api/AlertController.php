<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\AlertRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bandeja de alertas de la empresa.
 *
 *   GET  /api/alerts?status=unread|all&type=&vehicle_id=&from=&to=&page=
 *   GET  /api/alerts/unread-count
 *   POST /api/alerts/{alert}/read
 *   POST /api/alerts/read-all
 */
class AlertController extends Controller
{
    protected const PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['unread', 'all'])],
            'type' => ['nullable', Rule::in(AlertRule::TYPES)],
            'vehicle_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = Alert::with(['vehicle:id,name', 'rule:id,name'])
            ->where('company_id', $this->companyId($request))
            ->when(($filters['status'] ?? 'all') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['vehicle_id'] ?? null, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('triggered_at', '>=', $this->dayStart($d)))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('triggered_at', '<', $this->dayStart($d)->addDay()))
            ->orderByDesc('triggered_at')
            ->orderByDesc('id');

        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map->toApi()->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread' => $this->unread($request),
            ],
        ]);
    }

    /**
     * Para el número en el menú. Un admin que no está viendo a un cliente no tiene bandeja: 0.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $companyId = $request->user()->visibleCompanyId();

        return response()->json(['data' => [
            'unread' => $companyId === null ? 0 : Alert::where('company_id', $companyId)->whereNull('read_at')->count(),
        ]]);
    }

    public function markRead(Request $request, Alert $alert): JsonResponse
    {
        abort_unless($alert->company_id === $this->companyId($request), 404);

        if (!$alert->read_at) {
            $alert->update(['read_at' => now(), 'read_by' => $request->user()->id]);
        }

        return response()->json(['data' => $alert->load('vehicle:id,name', 'rule:id,name')->toApi()]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = Alert::where('company_id', $this->companyId($request))
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'read_by' => $request->user()->id]);

        return response()->json(['data' => ['marked' => $count]]);
    }

    protected function unread(Request $request): int
    {
        return Alert::where('company_id', $this->companyId($request))->whereNull('read_at')->count();
    }

    protected function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, AlertRule::TIMEZONE)->startOfDay()->utc();
    }

    protected function companyId(Request $request): int
    {
        return (int) $request->attributes->get('company_id');
    }
}
