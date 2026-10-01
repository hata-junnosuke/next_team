<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ワーカー一覧の絞り込み（F-04、GET /workers）。q は氏名の部分一致、is_active は有効/無効。
 * クエリ文字列なので is_active は "1" / "0" / "true" / "false" を受け付ける（空や未指定なら絞り込まない）。
 */
class IndexWorkerRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'in:1,0,true,false'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => '氏名',
            'is_active' => '有効／無効',
        ];
    }

    /**
     * 検索語（未指定・空なら null）
     */
    public function keyword(): ?string
    {
        $q = $this->validated('q');

        return $q === null || $q === '' ? null : $q;
    }

    /**
     * 有効/無効の絞り込み（未指定・空なら null = 絞り込まない）
     */
    public function isActive(): ?bool
    {
        $value = $this->validated('is_active');

        return $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
