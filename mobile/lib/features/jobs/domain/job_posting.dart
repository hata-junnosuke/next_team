// 募集のモデル（データの型）。画面とリポジトリの両方がこの型でやりとりする。
// TypeScript で言うと web/ の zod スキーマから作る型（type JobPosting = z.infer<...>）に当たる。
// 項目は docs/01 の 6.11 の WorkerJobPostingResource に合わせ、Step 7 で API に替えても画面を直さずに済むようにする。
// 残り枠（capacity / remaining）は「残り○枠」のバッジのコミットで足す。
//
// クラス: https://dart.dev/language/classes
// コンストラクタ: https://dart.dev/language/constructors

// 拠点（WorkerJobPostingResource の location: {id, name}）。
class JobLocation {
  // this.id: 受け取った引数をそのままフィールドに入れる短い書き方（TypeScript の constructor(public id: number)）。
  const JobLocation({required this.id, required this.name});

  final int id;
  final String name;
}

// 募集 1 件。全部の項目が final なので、作ったあとは変えられない（不変）。
// 変えたいときは新しい値を作る（React の state を直接書き換えないのと同じ考え方）。
class JobPosting {
  const JobPosting({
    required this.id,
    required this.location,
    required this.jobTitle,
    required this.workDate,
    required this.startTime,
    required this.endTime,
    required this.hourlyWage,
    required this.transportAllowance,
  });

  final int id;
  final JobLocation location;
  // 職種（job_title）。Dart の名前は lowerCamelCase にする（JSON の snake_case との変換は fromJson のコミットで書く）。
  final String jobTitle;
  // 勤務日。時刻は使わず、日付だけを見る。
  final DateTime workDate;
  // 開始・終了の時刻は API と同じ "09:00" の文字列のまま持つ（表示するだけで計算しないため）。
  final String startTime;
  final String endTime;
  // 時給と交通費（円）。
  final int hourlyWage;
  final int transportAllowance;
}
