// よてい タブの中のタブ（確定 / 応募中）の定義（Step 8）。
// 選んだタブを、API の GET /api/worker/applications の status（絞り込み）に渡す値に変える（docs/01 の 6.11）。
// 作りは日付のチップの DateFilter（date_filter.dart）と同じ enum。
//
// ここで持つのは「どのタブがあるか」と「そのタブで API に何を頼むか」だけ。
// 状態の文言（確定・応募中）は、一覧の各行では API の status.label を出す（7A.4 の「状態の文言」）。
// タブの名前（label）だけは、まだ API から何も届いていないうちに描くので、アプリが持つ。
//
// enum: https://dart.dev/language/enums

enum ScheduleTab {
  // 各値は、下のコンストラクタに (タブの名前, API に送る status の値) を渡して作る。
  // 並び順がそのままタブの並び順になる（ScheduleTab.values）。
  confirmed('確定', 'confirmed'),
  applied('応募中', 'applied');

  const ScheduleTab(this.label, this.status);

  // タブに出す名前。
  final String label;

  // GET /api/worker/applications?status=… に送る値（docs/01 の 6.11 の status）。
  // 取り下げられるかどうかの判定には使わない（それは API の can.withdraw。7A.4）。
  final String status;
}
