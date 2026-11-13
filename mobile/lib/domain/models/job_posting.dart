// 募集のモデル（データの型）。画面とリポジトリの両方がこの型でやりとりする。
// TypeScript で言うと web/ の zod スキーマから作る型（type JobPosting = z.infer<...>）に当たる。
// 項目の名前と形は docs/01 の 6.11 の WorkerJobPostingResource に合わせ、Step 7 で API に替えても画面を直さずに済むようにする。
// ただし今は Step 3 の画面で使う項目だけ。status / my_application / can は Step 7 で足す。
//
// JSON（API の形）から作る fromJson を、手で書いている（Step 7 で freezed + json_serializable の生成に替える）。
// web/ で zod の parse をするのと同じく、形が違えばここで例外になる（契約のずれに気づける。docs/01 の 7A.4）。
//
// クラス: https://dart.dev/language/classes
// コンストラクタ: https://dart.dev/language/constructors
// JSON を手で読む: https://docs.flutter.dev/data-and-backend/serialization/json

// 拠点（WorkerJobPostingResource の location: {id, name}）。
class JobLocation {
  // this.id: 受け取った引数をそのままフィールドに入れる短い書き方（TypeScript の constructor(public id: number)）。
  const JobLocation({required this.id, required this.name});

  // factory コンストラクタ: 決まった手順で値を作って返すコンストラクタ（Ruby のクラスメソッド self.from_json に近い）。
  // Map<String, dynamic>: キーが文字列、値は何でも入る Map（jsonDecode が返す形。TypeScript の Record<string, any>）。
  // dynamic は「型が分からない値」。analysis_options.yaml の strict-casts があるので、
  // json['id'] をそのまま int に入れられず、as int と書いて「int のはず」と確かめる必要がある。
  // 違う型（例: "1" という文字列）が来たら、as のところで例外になる（zod の parse が落ちるのと同じ）。
  factory JobLocation.fromJson(Map<String, dynamic> json) {
    return JobLocation(id: json['id'] as int, name: json['name'] as String);
  }

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
    required this.capacity,
    required this.remaining,
  });

  // JSON のキーは snake_case（API の形）、Dart のフィールドは lowerCamelCase。その対応をここで 1 回だけ書く。
  factory JobPosting.fromJson(Map<String, dynamic> json) {
    return JobPosting(
      id: json['id'] as int,
      // 入れ子の Map は、さらに as Map<String, dynamic> と確かめてから、その型の fromJson に渡す。
      location: JobLocation.fromJson(json['location'] as Map<String, dynamic>),
      jobTitle: json['job_title'] as String,
      // "2026-09-20" の文字列を日付にする。形が違えば DateTime.parse が例外を投げる。
      workDate: DateTime.parse(json['work_date'] as String),
      startTime: json['start_time'] as String,
      endTime: json['end_time'] as String,
      hourlyWage: json['hourly_wage'] as int,
      transportAllowance: json['transport_allowance'] as int,
      capacity: json['capacity'] as int,
      remaining: json['remaining'] as int,
    );
  }

  final int id;
  final JobLocation location;
  // 職種（job_title）。Dart の名前は lowerCamelCase にする（JSON の snake_case との変換は下の fromJson でしている）。
  final String jobTitle;
  // 勤務日。時刻は使わず、日付だけを見る。
  final DateTime workDate;
  // 開始・終了の時刻は API と同じ "09:00" の文字列のまま持つ（表示するだけで計算しないため）。
  final String startTime;
  final String endTime;
  // 時給と交通費（円）。
  final int hourlyWage;
  final int transportAllowance;
  // 定員と残り枠（人数）。残り枠は API が数えて返す値をそのまま持つ（docs/01 の 6.11 の remaining）。
  // アプリで「定員 − 確定数」を計算しないのは、数え方（どの状態の応募を数えるか）が業務ルールだから。
  final int capacity;
  final int remaining;
}
