<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 応募の代行入力（F-09）。ワーカーは自社のもの（他社の ID は「存在しない」と同じ 422）。
 * 募集はルートモデルバインディングの値を使い、本文からは取らない（docs/01 の API 契約（募集））。
 * 有効かどうか・二重応募は業務ルールなので ApplicationService が見る。
 * https://readouble.com/laravel/13.x/ja/validation.html#form-request-validation
 */
class StoreApplicationRequest extends FormRequest
{
    /**
     * 認可は auth:sanctum + company ミドルウェアとグローバルスコープに任せる（Policy は作らない。docs/01 のテナント分離）
     */
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
            // Rule::exists はグローバルスコープを通らない（クエリビルダで直接引く）ので、自社の条件を必ず付ける
            'worker_id' => ['required', 'integer', Rule::exists('workers', 'id')->where('company_id', CurrentCompany::id())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['worker_id' => 'ワーカー'];
    }
}
