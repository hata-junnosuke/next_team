<?php

namespace App\Http\Requests;

/**
 * ワーカーの更新（F-02）。PUT なので登録と同じ項目・同じ規則（docs/01 6.3「同上」）。
 * 有効/無効はここでは変えない（ToggleWorkerActiveRequest）。
 */
class UpdateWorkerRequest extends StoreWorkerRequest {}
