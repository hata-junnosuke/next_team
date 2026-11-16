// 自分の応募 1 件（API の WorkerApplicationResource。docs/01 の 6.11）。
// 応募（POST /api/worker/job-postings/{id}/applications）の 201 の応答がこの形になる。
// Step 8 の「よてい」（GET /api/worker/applications）の一覧の 1 件も同じ形。
// TypeScript で言うと、zod の applicationSchema と z.infer の型の組み合わせ。
//
// Step 7 では応答を型にして「形が契約どおりか」を確かめるだけで、画面には出さない
// （成功したらスナックバーを出し、詳細と一覧を API から取り直す。docs/01 の 7A.1 の Q4）。
//
// freezed の書き方は job_posting.dart を参照。

import 'package:freezed_annotation/freezed_annotation.dart';

import 'job_posting.dart';
import 'labeled_value.dart';

part 'worker_application.freezed.dart';
part 'worker_application.g.dart';

@freezed
abstract class WorkerApplication with _$WorkerApplication {
  const factory WorkerApplication({
    required int id,
    // 応募した募集の要約（JSON では job_posting）。残り枠や can は入っていない（一覧の募集とは別の形）。
    required ApplicationJobPosting jobPosting,
    // 応募の状態（{value: "applied", label: "応募中"}）。
    required LabeledValue status,
    // 日時は ISO 8601（"2026-09-20T09:00:00+09:00"）。生成した fromJson が DateTime.parse で読む。
    required DateTime appliedAt,
    // 確定したときだけ値が入る（応募中なら null）。
    required DateTime? confirmedAt,
    required int? confirmedHourlyWage,
    required int? confirmedTransportAllowance,
    // キャンセルの理由区分（{value, label}）。キャンセルされていなければ null。
    required LabeledValue? cancelReason,
    required DateTime? cancelledAt,
  }) = _WorkerApplication;

  factory WorkerApplication.fromJson(Map<String, dynamic> json) =>
      _$WorkerApplicationFromJson(json);
}

// 応募の中の募集の要約（WorkerApplicationResource の job_posting）。
@freezed
abstract class ApplicationJobPosting with _$ApplicationJobPosting {
  const factory ApplicationJobPosting({
    required int id,
    required String jobTitle,
    required DateTime workDate,
    required String startTime,
    required String endTime,
    // 拠点の形 {id, name} は募集一覧と同じなので、JobLocation を使い回す。
    required JobLocation location,
  }) = _ApplicationJobPosting;

  factory ApplicationJobPosting.fromJson(Map<String, dynamic> json) =>
      _$ApplicationJobPostingFromJson(json);
}
