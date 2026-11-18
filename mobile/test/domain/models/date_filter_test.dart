// DateFilter.range（日付の絞り込みの範囲）のテスト（Step 9。Step 3 の enum と switch の復習）。
// 「今日」は引数で渡す。DateTime.now() に頼ると、テストを回した日や時刻（真夜中）で結果が変わる
// （rules/common/testing.md）。lib/ の側も range(today) と今日を受け取る形にしてあるので、テストしやすい。

import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/domain/models/date_filter.dart';

void main() {
  // 2026-09-16 は水曜日。
  final wednesday = DateTime(2026, 9, 16);

  test('all は null', () {
    // null = 日付で絞り込まない（API に date_from / date_to を送らない）。
    expect(DateFilter.all.range(wednesday), isNull);
  });

  test('today は今日だけ', () {
    // range の戻り値はレコード（名前付きの組。TypeScript の { from, to } のオブジェクトに近い）。
    // https://dart.dev/language/records
    final range = DateFilter.today.range(wednesday)!;

    expect(range.from, DateTime(2026, 9, 16));
    expect(range.to, DateTime(2026, 9, 16));
  });

  test('tomorrow は月末をまたいでも翌日', () {
    // 9/30 の翌日は 10/1（DateTime(2026, 9, 31) は Dart が 10/1 に繰り上げる）。
    final range = DateFilter.tomorrow.range(DateTime(2026, 9, 30))!;

    expect(range.from, DateTime(2026, 10, 1));
    expect(range.to, DateTime(2026, 10, 1));
  });

  test('thisWeek は日曜まで、日曜なら今日だけ', () {
    final fromWednesday = DateFilter.thisWeek.range(wednesday)!;
    expect(fromWednesday.from, DateTime(2026, 9, 16));
    expect(fromWednesday.to, DateTime(2026, 9, 20));

    // 2026-09-20 は日曜日。
    final fromSunday = DateFilter.thisWeek.range(DateTime(2026, 9, 20))!;
    expect(fromSunday.from, DateTime(2026, 9, 20));
    expect(fromSunday.to, DateTime(2026, 9, 20));
  });
}
