<?php

namespace App\Http\Resources\Worker;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ワーカーアプリに返す本人の情報（docs/01 の 6.11 の WorkerMeResource）。
 * email はログインに使うメール（workers.email。小文字で保存している）。company.code はアプリが次のログインの入力欄に入れておくために返す。
 *
 * @mixin Worker
 */
class WorkerMeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'code' => $this->company->code,
            ]),
        ];
    }
}
