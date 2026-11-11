// アプリの依存の組み立て: どの実装を使うかを決める Provider を置く所（Flutter 公式の case study の config/dependencies.dart）。
// Step 4 の jobRepositoryProvider に加えて、Step 6 から API の呼び出し（apiClientProvider）と
// トークンの保存先（tokenStorageProvider）もここで作る。
// data/ は Riverpod を import しないので、Provider は data/ の外のここに置き、
// 「どの実装を使うか」の差し替えは、この 1 か所でだけ行う（docs/01 の 7A.1。issue #35 の T10）。
// Rails で言うと config/initializers で「このインターフェースにはこの実装を使う」と決める所。
// Next.js で言うと、lib/api.ts を偽物に差し替える 1 行（モックの切り替え）を 1 ファイルにまとめたもの。
//
// Provider とは: https://riverpod.dev/ja/docs/concepts2/providers
// コード生成について: https://riverpod.dev/ja/docs/concepts/about_code_generation

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../data/repositories/auth_repository.dart';
import '../data/repositories/fake_job_repository.dart';
import '../data/repositories/job_repository.dart';
import '../data/services/api_client.dart';
import '../data/services/auth_interceptor.dart';
import '../data/services/token_storage.dart';

// part: 生成されたファイル（dependencies.g.dart）を、このファイルの一部として取り込む印。
// .g.dart は `dart run build_runner build` で作られる。手で書き換えない（作り直すと消える）。
// 元のコード（下の @riverpod の関数）を変えたら、もう一度 build_runner を回す。
part 'dependencies.g.dart';

// @riverpod を付けた関数から、build_runner が jobRepositoryProvider という変数を作る（.g.dart の中）。
// 関数の名前 jobRepository に Provider を付けた名前になる。
// 一覧は jobListProvider、詳細は jobDetailProvider の中で ref.watch(jobRepositoryProvider) して受け取る。
// 画面（ui/<機能>/widgets/）はこの Provider を直接読まない（view_models/ を通す）。
//
// 何を持つか: リポジトリの実体 1 つ。
// いつ作り直されるか: 作り直さない。keepAlive: true にすると、誰も読んでいない間も捨てずに持ち続ける。
// 既定（keepAlive なし）だと、読む画面がなくなったときに捨てられ、次に読まれたときに作り直される。
// リポジトリは画面に出す状態を持たない道具なので、アプリが動いている間 1 つを使い回せばよい
// （Step 6 で ApiClient を持つようになると、作り直さないことに意味が出てくる）。
// 偽のリポジトリは「3 回に 1 回失敗」のために呼ばれた回数を持つ。作り直すと数え直しになる。
//
// Ref: Provider の中からほかの Provider を読むための入口（下の authRepositoryProvider で ref.watch を使う）。
@Riverpod(keepAlive: true)
JobRepository jobRepository(Ref ref) {
  // Step 7 で本物の API に替えるときは、この 1 行を `ApiJobRepository(...)` にするだけ（docs/01 の 7A.1）。
  // FakeJobRepository は呼ばれた回数を数えるので const ではない。keepAlive なので 1 つだけ作られる。
  return FakeJobRepository();
}

// トークンの保存先（Keychain）。
// 何を持つか: TokenStorage の実体 1 つ。
// いつ作り直されるか: 作り直さない（keepAlive）。中身（トークン）は Keychain にあり、この Provider は窓口だけ。
@Riverpod(keepAlive: true)
TokenStorage tokenStorage(Ref ref) => TokenStorage();

// API の呼び出し口（dio を包んだもの）。
// 何を持つか: ApiClient の実体 1 つ。接続先は --dart-define=API_BASE_URL（main.dart が空でないことを確かめ済み）。
// いつ作り直されるか: 作り直さない（keepAlive）。作り直すと dio の設定（タイムアウトやインターセプタ）を毎回組み立てることになる。
// AuthInterceptor にトークンの保存先を渡し、すべてのリクエストに Bearer を付けさせる（T9）。
@Riverpod(keepAlive: true)
ApiClient apiClient(Ref ref) => ApiClient(
  baseUrl: apiBaseUrl,
  interceptors: [AuthInterceptor(tokenStorage: ref.watch(tokenStorageProvider))],
);

// ログイン・初回登録・ログアウトと、ログインの状態を持つリポジトリ（docs/01 の 7A.1。issue #46 の Q1）。
// 何を持つか: AuthRepository の実体 1 つ。ログインの状態（isLoggedIn）はこの中にある。
// いつ作り直されるか: 作り直さない（keepAlive）。作り直すとログインの状態が消え、
// go_router が聞いている相手（refreshListenable）もずれてしまう。
// ref.watch(apiClientProvider): 上の ApiClient を受け取る（Provider から Provider を読む）。
// 片付け: Provider が捨てられるとき（アプリの終了時）に ChangeNotifier の dispose を呼ぶ（ref.onDispose）。
@Riverpod(keepAlive: true)
AuthRepository authRepository(Ref ref) {
  final repository = AuthRepository(
    apiClient: ref.watch(apiClientProvider),
    tokenStorage: ref.watch(tokenStorageProvider),
  );
  ref.onDispose(repository.dispose);
  return repository;
}
