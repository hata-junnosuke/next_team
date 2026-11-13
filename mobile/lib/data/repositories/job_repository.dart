// 募集を取ってくるリポジトリの「約束（型）」。
// どの実装を使うかは config/dependencies.dart の jobRepositoryProvider が決める（issue #35 の T10）。
// このファイル（と data/ 全体）は Riverpod を import しない（docs/01 の 7A.1）。
// 相手が偽物（Step 3〜6 の FakeJobRepository）か本物の API（Step 7 からの ApiJobRepository）かは、使う側（jobListProvider・jobDetailProvider）は知らない。
// Next.js で言うと、web/ の lib/api.ts の関数の型（シグネチャ）だけを公開しているのに近い。
// Rails で言うと、サービスのインターフェース（PORO）。どの実装を使うかの設定は別の 1 か所に置く。
//
// クラスの修飾子（interface など）: https://dart.dev/language/class-modifiers

import '../../domain/models/job_posting.dart';
import '../../domain/models/worker_application.dart';

// abstract interface class = 「このメソッドを持つこと」という約束だけを書いた型（TypeScript の interface）。
// - abstract: 中身のないメソッドを持てる。この型そのものは new（作成）できない
// - interface: ほかのファイルからは implements（約束を守る）だけができ、extends（中身の継承）はできない
// view_models/ の Provider はこの型に対して書くので、実装を替えても Provider と画面のコードは変わらない。
abstract interface class JobRepository {
  // 募集の一覧。勤務日 → 開始時刻の順（API の並び順と同じ。docs/01 の 6.11）。
  // from / to を渡すと、勤務日がその範囲（両端を含む）の募集だけを返す。null なら絞り込まない。
  // ApiJobRepository は、これをそのまま API の date_from / date_to（YYYY-MM-DD）にして渡す（docs/01 の 6.11）。
  // {DateTime? from, DateTime? to}: { } で囲んだ引数は「名前付き引数」で、呼ぶ側は fetchJobPostings(from: …, to: …)
  // と名前を書く（TypeScript で引数を 1 つのオブジェクト { from, to } にするのに近い）。省略すると null になる。
  Future<List<JobPosting>> fetchJobPostings({DateTime? from, DateTime? to});

  // id で 1 件。見つからなければ null（API なら 404）。
  Future<JobPosting?> findJobPosting(int id);

  // 募集に応募する（Step 7）。成功したら自分の応募（WorkerApplication）を返す。
  // 応募できないとき（満員・二重応募・締切など）は API の 422 で ApiError を投げる。可否の判定はアプリでしない
  // （CLAUDE.md の絶対ルール 1）。見つからない募集は 404 の ApiError（一覧・詳細と違い null にしない。送る操作なので失敗として出す）。
  Future<WorkerApplication> apply(int jobPostingId);
}
