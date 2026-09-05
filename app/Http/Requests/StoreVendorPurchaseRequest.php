<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit' => ['nullable', 'string', 'max:20'],
            'unit_cost' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'purchase_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
