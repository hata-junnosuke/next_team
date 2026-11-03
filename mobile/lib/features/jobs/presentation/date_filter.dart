// 募集一覧の日付の絞り込み（すべて / 今日 / 明日 / 今週）。
// 選んだものを「日付の範囲（から・まで）」に変える。Step 7 では、この範囲を API の
// date_from / date_to（docs/01 の 6.11）に渡して、絞り込みを API に任せる。
//
// enum: https://dart.dev/language/enums
// レコード (a, b): https://dart.dev/language/records
// switch 式: https://dart.dev/language/branches#switch-expressions

// enum = 決まった選択肢だけを持つ型（TypeScript の 'all' | 'today' | ... のユニオン型に近い）。
// Dart の enum はフィールドとメソッドを持てる（enhanced enum）。ここでは画面に出す文言 label を持たせる。
enum DateFilter {
  // 各値は、下のコンストラクタに label を渡して作る。
  all('すべて'),
  today('今日'),
  tomorrow('明日'),
  thisWeek('今週');

  const DateFilter(this.label);

  final String label;

  // 日付の範囲を返す。すべてなら null（絞り込まない）。
  // ({DateTime from, DateTime to}) は「レコード」= 名前付きの値を組にした使い捨ての型
  // （TypeScript の { from: Date; to: Date } をその場で書くのに近い）。クラスを作るほどでもない組に使う。
  ({DateTime from, DateTime to})? range(DateTime today) {
    // switch 式: 値によって返す式を選ぶ（JavaScript の switch 文と違い、式なので値を返す）。
    // enum の値を全部書かないとコンパイルエラーになるので、選択肢を足したときに書き忘れない。
    return switch (this) {
      DateFilter.all => null,
      DateFilter.today => (from: today, to: today),
      DateFilter.tomorrow => (
        from: today.add(const Duration(days: 1)),
        to: today.add(const Duration(days: 1)),
      ),
      // 今週 = 今日から、その週の日曜まで（weekday は 月曜 = 1 … 日曜 = 7）。
      DateFilter.thisWeek => (
        from: today,
        to: today.add(Duration(days: DateTime.sunday - today.weekday)),
      ),
    };
  }
}
