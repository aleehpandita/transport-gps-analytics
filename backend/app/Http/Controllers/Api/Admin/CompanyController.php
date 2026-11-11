<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Panel de EkMira: nuestros clientes.
 *
 *   GET   /api/admin/companies              todas, con conteo de unidades, usuarios y equipos sin señal
 *   POST  /api/admin/companies              alta de empresa
 *   GET   /api/admin/companies/{company}    detalle con unidades y usuarios
 *   PATCH /api/admin/companies/{company}    editar datos, plan o suspender
 *   POST  /api/admin/view-as                {company_id|null}: ver el panel como esa empresa
 */
class CompanyController extends Controller
{
    public function __construct(protected AccountService $accounts)
    {
    }

    public function index(): JsonResponse
    {
        $companies = Company::query()
            ->with(['vehicles.latestPosition'])
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $companies->map(function (Company $company) {
                $vehicles = $company->vehicles->map(fn ($v) => $this->accounts->vehicleRow($v));

                return $this->companyRow($company) + [
                    'vehicles_count' => $vehicles->count(),
                    'vehicles_without_signal' => $vehicles->where('signal', '!=', 'ok')->count(),
                    'users_count' => $company->users_count,
                    'last_report_at' => $vehicles->pluck('last_report_at')->filter()->max(),
                ];
            })->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = Company::create($this->validated($request));

        return response()->json(['data' => $this->companyRow($company)], 201);
    }

    public function show(Company $company): JsonResponse
    {
        $company->load(['vehicles.latestPosition', 'users']);

        return response()->json([
            'data' => $this->companyRow($company) + [
                'vehicles' => $company->vehicles->sortBy('name')->map(fn ($v) => $this->accounts->vehicleRow($v))->values(),
                'users' => $company->users->sortBy('name')->map(fn ($u) => $this->accounts->userRow($u))->values(),
            ],
        ]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $company->update($this->validated($request, partial: true));

        return response()->json(['data' => $this->companyRow($company->fresh())]);
    }

    /**
     * El admin ve el panel exactamente como lo ve esa empresa (sus unidades nada más).
     * Con company_id null regresa a ver todo.
     */
    public function viewAs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
        ]);
        abort_unless($request->hasSession(), 400, 'Ver como cliente solo funciona desde el panel.');

        if (empty($validated['company_id'])) {
            $request->session()->forget(User::ACTING_COMPANY_SESSION_KEY);
        } else {
            $request->session()->put(User::ACTING_COMPANY_SESSION_KEY, (int) $validated['company_id']);
        }

        return response()->json(['data' => ['acting_company_id' => $validated['company_id'] ?? null]]);
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'rfc' => ['nullable', 'string', 'between:12,13'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'plan' => ['nullable', 'string', 'max:50'],
            'monthly_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in(Company::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    protected function companyRow(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'rfc' => $company->rfc,
            'contact_name' => $company->contact_name,
            'contact_email' => $company->contact_email,
            'contact_phone' => $company->contact_phone,
            'plan' => $company->plan,
            'monthly_price' => $company->monthly_price !== null ? (float) $company->monthly_price : null,
            'status' => $company->status,
            'notes' => $company->notes,
            'created_at' => $company->created_at?->toIso8601String(),
        ];
    }
}
