<?php

namespace App\Http\Requests;

/**
 * 募集の編集（F-05）。入力の検証は作成と同じ。
 * 「中止した募集は編集できない」「募集人数は確定数より少なくできない」は状態とデータによる業務ルールなので
 * ここではなく JobPostingService::update() が 422 にする。
 */
class UpdateJobPostingRequest extends StoreJobPostingRequest {}
