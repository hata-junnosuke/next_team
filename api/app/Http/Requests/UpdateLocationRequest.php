<?php

namespace App\Http\Requests;

/**
 * 拠点の更新（F-01）。PUT なので登録と同じ項目・同じ規則（docs/01 の API 契約（拠点）の「同上」）。
 */
class UpdateLocationRequest extends StoreLocationRequest {}
