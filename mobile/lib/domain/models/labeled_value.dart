// API が返す「値と、画面に出す文言」の組 {value, label}（docs/01 の 6.1。状態などの Enum）。
// 例: 募集の状態 {"value": "open", "label": "募集中"}、応募の状態 {"value": "applied", "label": "応募中"}。
// 文言（label）は API が返すものをそのまま出し、アプリで「applied なら応募中」と対応表を持たない（7A.4 の「状態の文言」）。
// アプリが value を見るのは、色を選ぶときだけ（web の lib/status.ts と同じ考え方）。
// TypeScript で言うと web/ の zod の labeledValue スキーマ（z.object({ value: z.string(), label: z.string() })）に当たる。
//
// freezed の書き方は job_posting.dart を参照。

import 'package:freezed_annotation/freezed_annotation.dart';

part 'labeled_value.freezed.dart';
part 'labeled_value.g.dart';

@freezed
abstract class LabeledValue with _$LabeledValue {
  const factory LabeledValue({required String value, required String label}) =
      _LabeledValue;

  factory LabeledValue.fromJson(Map<String, dynamic> json) =>
      _$LabeledValueFromJson(json);
}
