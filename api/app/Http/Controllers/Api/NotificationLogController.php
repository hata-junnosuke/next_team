<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNotificationLogRequest;
use App\Http\Resources\NotificationLogResource;
use App\Models\Application;
use App\Models\NotificationLog;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * 通知履歴（F-24、docs/01 の API 契約（通知履歴））。読み取りだけで業務ルールがないので、サービスを作らず Eloquent を直接呼ぶ（WorkerController と同じ）。
 * 他社の行は NotificationLog のグローバルスコープ（BelongsToCompany）で出ない。
 */
class NotificationLogController extends Controller
{
    /**
     * GET /api/notification-logs?kind=&status=&page=&per_page=（新しい順）
     *
     * 宛先・対象はポリモーフィックなので、with() で種類ごとに 1 クエリずつまとめて読み、
     * 対象が応募のときだけ、ラベルに使う募集とワーカーを morphWith で続けて読む（N+1 にしない）。
     * Rails の includes(:recipient, subject: ...) に近い。
     * https://readouble.com/laravel/13.x/ja/eloquent-relationships.html#nested-eager-loading-morphto-relationships
     */
    public function index(IndexNotificationLogRequest $request): AnonymousResourceCollection
    {
        $kind = $request->kind();
        $status = $request->status();

        $logs = NotificationLog::query()
            ->with([
                // User には BelongsToCompany（グローバルスコープ）がない（ログイン時に企業より先にメールで探すため）。
                // claim() が記録時に企業を確かめているが、読む側でも企業で絞って他社の管理者の名前を出さない（多層防御）。
                // Worker はグローバルスコープで絞られる
                'recipient' => fn (MorphTo $morphTo) => $morphTo->constrain([
                    User::class => fn (Builder $query) => $query->where('company_id', CurrentCompany::id()),
                ]),
                'subject' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    Application::class => ['jobPosting', 'worker'],
                ]),
            ])
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return NotificationLogResource::collection($logs);
    }
}
