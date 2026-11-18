// utils/format.dart（表示の形）のテスト（Step 9）。
// 表示を web の 7.5 とそろえる決まり（docs/01 の 7A.4 の「日付と時刻」）を守る。
// web で言うと lib/format.ts の関数のユニットテスト。

import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/utils/format.dart';

void main() {
  test('formatDate は 2026/09/20（日）の形', () {
    expect(formatDate(DateTime(2026, 9, 20)), '2026/09/20（日）');
    // 月と日は 2 桁にそろえる。2026-01-05 は月曜日。
    expect(formatDate(DateTime(2026, 1, 5)), '2026/01/05（月）');
  });

  test('formatYen は 3 桁ごとにカンマ', () {
    expect(formatYen(500), '500円');
    expect(formatYen(1200), '1,200円');
    expect(formatYen(1234567), '1,234,567円');
  });

  test('formatTimeRange は 09:00〜17:00 の形', () {
    expect(formatTimeRange('09:00', '17:00'), '09:00〜17:00');
  });
}
