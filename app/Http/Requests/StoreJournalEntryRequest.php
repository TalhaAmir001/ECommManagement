<?php

namespace App\Http\Requests;

use App\Models\JournalAccount;
use App\Models\JournalCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJournalEntryRequest extends FormRequest
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
        $direction = $this->input('direction') === 'income' ? 'income' : 'expense';

        return [
            'entry_date' => ['required', 'date'],
            'direction' => ['required', Rule::in(['expense', 'income'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists((new JournalCategory())->getTable(), 'id')
                    ->where('type', $direction)
                    ->where('archived', false),
            ],
            'payment_account_id' => [
                'required',
                'integer',
                Rule::exists((new JournalAccount())->getTable(), 'id')
                    ->where('is_payment', true)
                    ->where('archived', false),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['draft', 'posted'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category does not match the chosen direction (expense/income).',
            'payment_account_id.exists' => 'Pick a payment account (Cash or Bank) that is active.',
        ];
    }
}
