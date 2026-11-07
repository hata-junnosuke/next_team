// 募集を取ってくるリポジトリの「約束（型）」と、どの実装を使うかを決める Provider。
// 画面はこのファイルの JobRepository と jobRepositoryProvider だけを知っていればよく、
// 相手が偽物（FakeJobRepository）か本物の API（Step 7 の ApiJobRepository）かを知らない。
// Next.js で言うと、web/ の lib/api.ts の関数の型（シグネチャ）だけを公開しているのに近い。
// Rails で言うと、サービスのインターフェースと、どの実装を使うかの設定（config）を 1 か所に置いたもの。
//
// クラスの修飾子（interface など）: https://dart.dev/language/class-modifiers
// Provider とは: https://riverpod.dev/ja/docs/concepts2/providers
// コード生成について: https://riverpod.dev/ja/docs/concepts/about_code_generation

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../domain/job_posting.dart';
import 'fake_job_repository.dart';

// part: 生成されたファイル（job_repository.g.dart）を、このファイルの一部として取り込む印。
// .g.dart は `dart run build_runner build` で作られる。手で書き換えない（作り直すと消える）。
// 元のコード（下の @riverpod の関数）を変えたら、もう一度 build_runner を回す。
part 'job_repository.g.dart';

// abstract interface class = 「このメソッドを持つこと」という約束だけを書いた型（TypeScript の interface）。
// - abstract: 中身のないメソッドを持てる。この型そのものは new（作成）できない
// - interface: ほかのファイルからは implements（約束を守る）だけができ、extends（中身の継承）はできない
// 画面はこの型に対して書くので、実装を替えても画面のコードは変わらない。
abstract interface class JobRepository {
  // 募集の一覧。勤務日 → 開始時刻の順（API の並び順と同じ。docs/01 の 6.11）。
  // from / to を渡すと、勤務日がその範囲（両端を含む）の募集だけを返す。null なら絞り込まない。
  // Step 7 では、これをそのまま API の date_from / date_to（YYYY-MM-DD）にして渡す（docs/01 の 6.11）。
  // {DateTime? from, DateTime? to}: { } で囲んだ引数は「名前付き引数」で、呼ぶ側は fetchJobPostings(from: …, to: …)
  // と名前を書く（TypeScript で引数を 1 つのオブジェクト { from, to } にするのに近い）。省略すると null になる。
  Future<List<JobPosting>> fetchJobPostings({DateTime? from, DateTime? to});

  // id で 1 件。見つからなければ null（API なら 404）。
  Future<JobPosting?> findJobPosting(int id);
}

// どのリポジトリを使うかを決める Provider。
// @riverpod を付けた関数から、build_runner が jobRepositoryProvider という変数を作る（.g.dart の中）。
// 関数の名前 jobRepository に Provider を付けた名前になる。
// 画面からは ref.watch(jobRepositoryProvider) で JobRepository を受け取る（T3 から使う）。
//
// 何を持つか: リポジトリの実体 1 つ。
// いつ作り直されるか: 作り直さない。keepAlive: true にすると、誰も読んでいない間も捨てずに持ち続ける。
// 既定（keepAlive なし）だと、読む画面がなくなったときに捨てられ、次に読まれたときに作り直される。
// リポジトリは画面に出す状態を持たない道具なので、アプリが動いている間 1 つを使い回せばよい
// （Step 6 で ApiClient を持つようになると、作り直さないことに意味が出てくる）。
// 偽のリポジトリは「3 回に 1 回失敗」のために呼ばれた回数を持つ（T6）。作り直すと数え直しになる。
//
// Ref: Provider の中からほかの Provider を読むための入口（Step 6 で ref.watch(apiClientProvider) を書く）。
@Riverpod(keepAlive: true)
JobRepository jobRepository(Ref ref) {
  // Step 7 で本物の API に替えるときは、この 1 行を `ApiJobRepository(...)` にするだけ（docs/01 の 7A.1）。
  // FakeJobRepository は呼ばれた回数を数えるので const ではない（T6）。keepAlive なので 1 つだけ作られる。
  return FakeJobRepository();
}
