<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJournalCategoryRequest extends FormRequest
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
        $categoryId = $this->route('category')?->id;
        $type = $this->input('type') === 'income' ? 'income' : 'expense';

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('journal_categories', 'name')->ignore($categoryId),
            ],
            'type' => ['required', Rule::in(['expense', 'income'])],
            'default_account_id' => [
                'required',
                'integer',
                Rule::exists('journal_accounts', 'id')
                    ->where('type', $type)
                    ->where('archived', false),
            ],
            'color' => ['nullable', 'string', 'max:16'],
        ];
    }
}
