// 募集一覧のデータを持つ Provider。一覧の画面はここから読むだけで、自分では取りに行かない。
// React で言うと useQuery({ queryKey: ['jobs'], queryFn: fetchJobs }) の queryFn とキャッシュを、
// 画面の外に書いたものに当たる。
//
// Provider とは: https://riverpod.dev/ja/docs/concepts2/providers
// ref.watch / ref.read / ref.invalidate: https://riverpod.dev/ja/docs/concepts2/refs
// 自動の再試行（retry）: https://riverpod.dev/ja/docs/concepts2/retry

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../data/job_repository.dart';
import '../domain/job_posting.dart';

part 'job_list_provider.g.dart';

// 募集の一覧（絞り込む前の全部）。
// Future を返す関数に @riverpod を付けると、画面から読んだ値は AsyncValue<List<JobPosting>> になる。
// AsyncValue = 「読み込み中 / エラー / データ」のどれかを表す値（TanStack Query の isLoading / error / data を 1 つにしたもの）。
//
// 何を持つか: リポジトリから取ってきた一覧（キャッシュ）。
// いつ作られるか: 画面が初めて ref.watch したとき（それまでは取りに行かない）。
// いつ作り直されるか:
// - 中で ref.watch している jobRepositoryProvider が変わったとき（Step 7 で API に差し替えても、ここはそのまま）
// - ref.invalidate(jobListProvider) されたとき（T5 の引っ張って更新で使う）
// - keepAlive を付けていないので、読む画面がなくなると捨てられ、次に読まれたときに取り直す
//
// retry: 失敗したときに自動でやり直すかどうか。Riverpod 3 は既定で、失敗すると 200ms 後から間を倍にしながら
// 何度でもやり直す。それだと 3 回に 1 回のエラー（T6）がすぐ自動で直ってしまい、エラー画面と「再読み込み」を
// 試せないので、この Provider だけ自動のやり直しを切る（_noRetry）。やり直すかは人が「再読み込み」で決める。
// Step 7 で API につなぐとき、通信の切断だけ自動でやり直すかを決め直す（422 や 404 はやり直しても同じなので、やり直さない）。
@Riverpod(retry: _noRetry)
Future<List<JobPosting>> jobList(Ref ref) {
  // ref.watch: ほかの Provider の値を読み、それが変わったらこの Provider も作り直す（React の useEffect の依存配列に近い）。
  final repository = ref.watch(jobRepositoryProvider);
  return repository.fetchJobPostings();
}

// 自動でやり直さない、という retry の関数。
// retry は「何回目の失敗か」と「何の失敗か」を受け取り、次にやり直すまでの時間を返す。null を返すとやり直さない。
// アノテーション（@Riverpod(...)）の引数には、コンパイル時に決まる値しか書けないので、トップレベルの関数にする。
Duration? _noRetry(int retryCount, Object error) => null;
