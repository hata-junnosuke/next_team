// どのリポジトリを使うかを決める Provider。
// issue #35 の T5 で data/job_repository.dart から移した。data/ は Riverpod を知らないふつうの Dart にし、
// 「どの実装を使うか」の差し替えは、この providers/ の 1 か所でだけ行う（docs/01 の 7A.1）。
// Rails で言うと config/initializers で「このインターフェースにはこの実装を使う」と決める所。
// Next.js で言うと、lib/api.ts を偽物に差し替える 1 行（モックの切り替え）を 1 ファイルにまとめたもの。
//
// Provider とは: https://riverpod.dev/ja/docs/concepts2/providers
// コード生成について: https://riverpod.dev/ja/docs/concepts/about_code_generation

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../data/fake_job_repository.dart';
import '../data/job_repository.dart';

// part: 生成されたファイル（job_repository_provider.g.dart）を、このファイルの一部として取り込む印。
// .g.dart は `dart run build_runner build` で作られる。手で書き換えない（作り直すと消える）。
// 元のコード（下の @riverpod の関数）を変えたら、もう一度 build_runner を回す。
part 'job_repository_provider.g.dart';

// @riverpod を付けた関数から、build_runner が jobRepositoryProvider という変数を作る（.g.dart の中）。
// 関数の名前 jobRepository に Provider を付けた名前になる。
// 一覧は jobListProvider、詳細は jobDetailProvider の中で ref.watch(jobRepositoryProvider) して受け取る。
// 画面（presentation/）はこの Provider を直接読まない。
//
// 何を持つか: リポジトリの実体 1 つ。
// いつ作り直されるか: 作り直さない。keepAlive: true にすると、誰も読んでいない間も捨てずに持ち続ける。
// 既定（keepAlive なし）だと、読む画面がなくなったときに捨てられ、次に読まれたときに作り直される。
// リポジトリは画面に出す状態を持たない道具なので、アプリが動いている間 1 つを使い回せばよい
// （Step 6 で ApiClient を持つようになると、作り直さないことに意味が出てくる）。
// 偽のリポジトリは「3 回に 1 回失敗」のために呼ばれた回数を持つ。作り直すと数え直しになる。
//
// Ref: Provider の中からほかの Provider を読むための入口（Step 6 で ref.watch(apiClientProvider) を書く）。
@Riverpod(keepAlive: true)
JobRepository jobRepository(Ref ref) {
  // Step 7 で本物の API に替えるときは、この 1 行を `ApiJobRepository(...)` にするだけ（docs/01 の 7A.1）。
  // FakeJobRepository は呼ばれた回数を数えるので const ではない。keepAlive なので 1 つだけ作られる。
  return FakeJobRepository();
}
