<?php

namespace App\Http\Requests;

use App\Models\JournalAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJournalCategoryRequest extends FormRequest
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
        $type = $this->input('type') === 'income' ? 'income' : 'expense';

        return [
            'name' => ['required', 'string', 'max:120', 'unique:journal_categories,name'],
            'type' => ['required', Rule::in(['expense', 'income'])],
            'default_account_id' => [
                'required',
                'integer',
                Rule::exists((new JournalAccount())->getTable(), 'id')
                    ->where('type', $type)
                    ->where('archived', false),
            ],
            'color' => ['nullable', 'string', 'max:16'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_account_id.exists' => 'The default account must match the category type and be active.',
        ];
    }
}
