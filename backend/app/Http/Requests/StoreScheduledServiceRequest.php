<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScheduledServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // sin auth todavía — se agrega cuando el dashboard tenga login
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'destination_zone_id' => ['required', 'exists:zones,id'],
            'scheduled_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}