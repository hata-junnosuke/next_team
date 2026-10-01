<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 募集の作成（F-05）。終了は開始より後、募集人数は 1 以上、時給は最低賃金以上、拠点は自社のもの。
 * https://readouble.com/laravel/13.x/ja/validation.html#form-request-validation
 */
class StoreJobPostingRequest extends FormRequest
{
    /** 時給・交通費の上限（円）。桁の打ち間違い（1300 → 13000000）を止めるための安全柵で、業務上の上限ではない */
    public const MAX_YEN = 100000;

    /** 募集人数の上限（名）。同上 */
    public const MAX_CAPACITY = 1000;

    /**
     * 認可は auth:sanctum + company ミドルウェアとグローバルスコープに任せる（Policy は作らない。docs/01 4.2）
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 交通費は任意。省略・空欄（web のフォームは空欄を "" で送る）は 0 円として扱う（DB の既定値と同じ）
     */
    protected function prepareForValidation(): void
    {
        if (in_array($this->input('transport_allowance'), [null, ''], true)) {
            $this->merge(['transport_allowance' => 0]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Rule::exists はグローバルスコープを通らない（クエリビルダで直接引く）ので、自社の条件を必ず付ける
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('company_id', CurrentCompany::id())],
            'job_title' => ['required', 'string', 'max:255'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            // date_format があるので、after は同じ書式で開始時刻と比べる。同時刻も不可（より後）
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'capacity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_CAPACITY],
            'hourly_wage' => ['required', 'integer', 'min:'.$this->minWage(), 'max:'.self::MAX_YEN],
            'transport_allowance' => ['integer', 'min:0', 'max:'.self::MAX_YEN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_time.after' => '終了時刻は開始時刻より後にしてください。',
            'hourly_wage.min' => "時給は最低賃金（{$this->minWage()}円）以上にしてください。",
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'location_id' => '拠点',
            'job_title' => '職種名',
            'work_date' => '勤務日',
            'start_time' => '開始時刻',
            'end_time' => '終了時刻',
            'capacity' => '募集人数',
            'hourly_wage' => '時給',
            'transport_allowance' => '交通費',
        ];
    }

    private function minWage(): int
    {
        return (int) config('myteam.min_wage');
    }
}
