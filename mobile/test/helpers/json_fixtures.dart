// テストで使う、API と同じ形の JSON を作る関数（Step 9。docs/01 の 6.11）。
// Laravel で言うと、テストの中の Factory（JobPosting::factory()->make([...])）に当たる。
// ただしここで作るのはモデルではなく「API が返す JSON（Map）」で、それを fromJson に通すのがテストの中身。
//
// どの関数も「よくある 1 件」を返し、テストで確かめたい項目だけを名前付き引数で変える。
// 例: jobPostingJson(remaining: 0, canApply: false) で「満員の募集」になる。
// 形は docs/01 の 6.11 の WorkerJobPostingResource・WorkerApplicationResource に合わせる。
// api/ のコードは読まず、契約の文面だけから作っている（CLAUDE.md の絶対ルール 4）。

// Map<String, dynamic>: JSON のオブジェクト（TypeScript の Record<string, any>）。
// API の応答を jsonDecode したときと同じ型にしておくと、fromJson にそのまま渡せる。

// 募集 1 件（WorkerJobPostingResource）。
// {}: 名前付き引数（TypeScript で 1 つのオブジェクトを分割代入して受けるのに近い）。= の右が既定値。
// myApplication は「応募していない」が既定なので null。応募済みにしたいときは myApplicationJson() を渡す。
Map<String, dynamic> jobPostingJson({
  int id = 1,
  int locationId = 10,
  String locationName = '東京倉庫',
  String jobTitle = 'ピッキング',
  String workDate = '2026-09-20',
  String startTime = '09:00',
  String endTime = '17:00',
  int hourlyWage = 1200,
  int transportAllowance = 500,
  int capacity = 3,
  int remaining = 2,
  String statusValue = 'open',
  String statusLabel = '募集中',
  Map<String, dynamic>? myApplication,
  bool canApply = true,
}) {
  return {
    'id': id,
    'location': {'id': locationId, 'name': locationName},
    'job_title': jobTitle,
    'work_date': workDate,
    'start_time': startTime,
    'end_time': endTime,
    'hourly_wage': hourlyWage,
    'transport_allowance': transportAllowance,
    'capacity': capacity,
    'remaining': remaining,
    'status': {'value': statusValue, 'label': statusLabel},
    'my_application': myApplication,
    'can': {'apply': canApply},
  };
}

// 募集の中の「自分の応募」（my_application）。
Map<String, dynamic> myApplicationJson({
  int id = 100,
  String statusValue = 'applied',
  String statusLabel = '応募中',
}) {
  return {
    'id': id,
    'status': {'value': statusValue, 'label': statusLabel},
  };
}

// 一覧の応答（{data: [...], meta: {...}}。6.1 のページあり）。
// total を省略すると、出した件数と同じ（隠れている募集はない）。
Map<String, dynamic> jobPostingPageJson(
  List<Map<String, dynamic>> data, {
  int? total,
}) {
  return {
    'data': data,
    'meta': {
      'current_page': 1,
      'last_page': 1,
      'per_page': 100,
      // ??: 左が null なら右（JavaScript の ?? と同じ）。
      'total': total ?? data.length,
    },
  };
}

// 自分の応募 1 件（WorkerApplicationResource）。Step 8 から can.withdraw を持つ。
Map<String, dynamic> workerApplicationJson({
  int id = 100,
  int jobPostingId = 1,
  String jobTitle = 'ピッキング',
  String workDate = '2026-09-20',
  String statusValue = 'applied',
  String statusLabel = '応募中',
  bool canWithdraw = true,
}) {
  return {
    'id': id,
    'job_posting': {
      'id': jobPostingId,
      'job_title': jobTitle,
      'work_date': workDate,
      'start_time': '09:00',
      'end_time': '17:00',
      'location': {'id': 10, 'name': '東京倉庫'},
    },
    'status': {'value': statusValue, 'label': statusLabel},
    'applied_at': '2026-09-01T10:00:00+09:00',
    'confirmed_at': null,
    'confirmed_hourly_wage': null,
    'confirmed_transport_allowance': null,
    'cancel_reason': null,
    'cancelled_at': null,
    'can': {'withdraw': canWithdraw},
  };
}

// ログインの成功の応答（{data: {token, worker: WorkerMeResource}}。5.3・6.11）。
Map<String, dynamic> loginResponseJson({
  String token = 'test-token',
  String companyCode = 'ACME0001',
}) {
  return {
    'data': {
      'token': token,
      'worker': {
        'id': 1,
        'name': '山田 太郎',
        'email': 'worker@example.com',
        'company': {'id': 1, 'name': 'テスト企業', 'code': companyCode},
      },
    },
  };
}
