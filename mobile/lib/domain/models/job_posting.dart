// 募集のモデル（データの型）。画面とリポジトリの両方がこの型でやりとりする。
// TypeScript で言うと web/ の zod スキーマから作る型（type JobPosting = z.infer<...>）に当たる。
// 項目の名前と形は docs/01 の 6.11 の WorkerJobPostingResource に合わせる。
//
// Step 7 から freezed + json_serializable で生成する（docs/01 の 7A.4）。Step 3〜6 は手書きだった。
// 手で書くのは「どんな項目があるか」だけ。次のものは build_runner が job_posting.freezed.dart と
// job_posting.g.dart に作る（手で書き換えない。このファイルを変えたら dart run build_runner build をやり直す）:
// - ==・hashCode（中身が同じなら等しい。Riverpod の「値が変わったか」の判定に効く）、toString、copyWith
// - fromJson の中身（_$JobPostingFromJson）。JSON のキーは build.yaml の field_rename: snake で
//   jobTitle → job_title のように対応させる。形が違えば CheckedFromJsonException を投げる（build.yaml の checked）
//
// domain/ なので Flutter も Riverpod も import しない（docs/01 の 7A.1）。freezed_annotation（印だけのパッケージ）は使ってよい。
//
// freezed: https://pub.dev/packages/freezed
// json_serializable: https://pub.dev/packages/json_serializable
// JSON の手書きとコード生成の比較: https://docs.flutter.dev/data-and-backend/serialization/json

import 'package:freezed_annotation/freezed_annotation.dart';

// part: 生成されたファイルを、このファイルの一部として取り込む（riverpod の .g.dart と同じ仕組み）。
// .freezed.dart は freezed が、.g.dart は json_serializable が作る。
part 'job_posting.freezed.dart';
part 'job_posting.g.dart';

// 拠点（WorkerJobPostingResource の location: {id, name}）。
// @freezed: 「このクラスの ==・copyWith などを生成して」という印。
// abstract class ... with _$JobLocation: 生成された部品（mixin = 混ぜ込む部品。Ruby の include に近い）を取り込む。
// abstract なのは、本体（_JobLocation）を生成側が作り、このクラスそのものは new しないため。
@freezed
abstract class JobLocation with _$JobLocation {
  // const factory ... = _JobLocation: 「作るときは生成された _JobLocation を使う」という宣言。
  // ここに並べた引数が、そのまま final のフィールドになる（手書きのときのフィールドの宣言が要らない）。
  const factory JobLocation({required int id, required String name}) =
      _JobLocation;

  // fromJson は 1 行だけ書き、中身は生成された _$JobLocationFromJson に任せる。
  // 手書きのときの `json['id'] as int` が、生成側に移った。
  // 呼ぶ側（リポジトリ）は直に呼ばず、data/services/api_client.dart の parseModel() を通す（7A.4。issue #48 の Q1）。
  factory JobLocation.fromJson(Map<String, dynamic> json) =>
      _$JobLocationFromJson(json);
}

// 募集 1 件。全部の項目が final（不変）。変えたいときは copyWith で一部だけ替えた新しい値を作る
// （React の state を直接書き換えず {...job, x: 1} で作り直すのと同じ考え方）。
@freezed
abstract class JobPosting with _$JobPosting {
  const factory JobPosting({
    required int id,
    // 入れ子の {id, name} は、生成された fromJson が JobLocation.fromJson を呼んで型にする。
    required JobLocation location,
    // 職種（JSON では job_title）。
    required String jobTitle,
    // 勤務日（"2026-09-20"）。生成された fromJson が DateTime.parse で日付にする。時刻は使わない。
    required DateTime workDate,
    // 開始・終了の時刻は API と同じ "09:00" の文字列のまま持つ（表示するだけで計算しないため）。
    required String startTime,
    required String endTime,
    // 時給と交通費（円）。
    required int hourlyWage,
    required int transportAllowance,
    // 定員と残り枠（人数）。残り枠は API が数えて返す値をそのまま持つ（docs/01 の 6.11 の remaining）。
    // アプリで「定員 − 確定数」を計算しないのは、数え方（どの状態の応募を数えるか）が業務ルールだから。
    required int capacity,
    required int remaining,
  }) = _JobPosting;

  factory JobPosting.fromJson(Map<String, dynamic> json) =>
      _$JobPostingFromJson(json);
}
