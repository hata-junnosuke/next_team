<?php

namespace App\Http\Resources;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ワーカーの JSON（docs/01 の API 契約（ワーカー））:
 * {id, name, email, phone, is_active, registered_at, invitation_expires_at, can: {invite}}
 *
 * - registered_at: 初回登録の日時（未登録なら null）
 * - invitation_expires_at: 今使える招待の期限（使える招待がなければ null）。関連 usableInvitation から読む。
 *   一覧は with()、1 件の応答は loadMissing() でコントローラが読んでおく（N+1 にしない）
 * - can.invite: 招待を送れるか（未登録かつ有効。Worker::canBeInvited()）。判定の正は WorkerInvitationService で、web はこれを見てボタンを出すだけ
 *
 * @mixin Worker
 */
class WorkerResource extends JsonResource
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
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'registered_at' => $this->registered_at?->toIso8601String(),
            'invitation_expires_at' => $this->usableInvitation?->expires_at->toIso8601String(),
            'can' => [
                'invite' => $this->canBeInvited(),
            ],
        ];
    }
}
