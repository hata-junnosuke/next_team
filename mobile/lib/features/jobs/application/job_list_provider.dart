// 募集一覧のデータを持つ Provider。一覧の画面はここから読むだけで、自分では取りに行かない。
// React で言うと useQuery({ queryKey: ['jobs'], queryFn: fetchJobs }) の queryFn とキャッシュを、
// 画面の外に書いたものに当たる。
//
// Provider とは: https://riverpod.dev/ja/docs/concepts2/providers
// ref.watch / ref.read / ref.invalidate: https://riverpod.dev/ja/docs/concepts2/refs
// 自動の再試行（retry）: https://riverpod.dev/ja/docs/concepts2/retry
// 引数つきの Provider（family）: https://riverpod.dev/ja/docs/concepts2/family

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../data/job_repository.dart';
import '../domain/date_filter.dart';
import '../domain/job_posting.dart';

part 'job_list_provider.g.dart';

// 日付のチップで絞り込んだ募集の一覧。
// Future を返す関数に @riverpod を付けると、画面から読んだ値は AsyncValue<List<JobPosting>> になる。
// AsyncValue = 「読み込み中 / エラー / データ」のどれかを表す値（TanStack Query の isLoading / error / data を 1 つにしたもの）。
//
// 引数つきの Provider（family）: 関数に Ref のほかの引数（filter）を足すと、引数ごとに別の Provider になる。
// 画面からは jobListProvider(DateFilter.today) のように、関数を呼ぶ形で読む。
// 「すべて」と「今日」は別々に値を持ち、別々に取りに行き、別々に捨てられる
// （React の useQuery で queryKey: ['jobs', filter] とキーに条件を入れるのと同じ）。
// 引数は == で同じかを比べるので、enum のように「同じ値なら == が true」のものを渡す。
//
// 何を持つか: その条件でリポジトリから取ってきた一覧（キャッシュ）。条件ごとに 1 つずつ。
// いつ作られるか: 画面が初めて ref.watch したとき（それまでは取りに行かない）。
// いつ作り直されるか:
// - 中で ref.watch している jobRepositoryProvider が変わったとき（Step 7 で API に差し替えても、ここはそのまま）
// - ref.invalidate(jobListProvider(filter)) されたとき（T5 の引っ張って更新、T6 の再読み込み）
// - keepAlive を付けていないので、読む画面がなくなると捨てられ、次に読まれたときに取り直す。
//   チップを「すべて」から「今日」に変えると、誰も読まなくなった jobListProvider(DateFilter.all) は捨てられる。
//   だから「すべて」に戻すと、もう一度取りに行く（auto dispose が目に見える所）
//
// retry: 失敗したときに自動でやり直すかどうか。Riverpod 3 は既定で、Exception で失敗すると 200ms 後から
// 間を倍にしながら（6.4 秒で頭打ち）最大 10 回やり直す（Error = プログラムのバグはやり直さない）。
// それだと 3 回に 1 回のエラー（T6）がすぐ自動で直ってしまい、エラー画面と「再読み込み」を
// 試せないので、この Provider だけ自動のやり直しを切る（_noRetry）。やり直すかは人が「再読み込み」で決める。
// Step 7 で API につなぐとき、通信の切断だけ自動でやり直すかを決め直す（422 や 404 はやり直しても同じなので、やり直さない）。
@Riverpod(retry: _noRetry)
Future<List<JobPosting>> jobList(Ref ref, DateFilter filter) {
  // ref.watch: ほかの Provider の値を読み、それが変わったらこの Provider も作り直す（React の useEffect の依存配列に近い）。
  final repository = ref.watch(jobRepositoryProvider);
  // チップを日付の範囲（から・まで）に変える。「すべて」なら null で、絞り込まない。
  // 今日は 0 時にそろえる（DateTime(年, 月, 日) は時刻を 0 時にする）。
  final now = DateTime.now();
  final range = filter.range(DateTime(now.year, now.month, now.day));
  // ?. は「左が null なら、右を読まずに null」（JavaScript のオプショナルチェーン range?.from と同じ）。
  return repository.fetchJobPostings(from: range?.from, to: range?.to);
}

// 自動でやり直さない、という retry の関数。
// retry は「何回目の失敗か」と「何の失敗か」を受け取り、次にやり直すまでの時間を返す。null を返すとやり直さない。
// アノテーション（@Riverpod(...)）の引数には、コンパイル時に決まる値しか書けないので、トップレベルの関数にする。
Duration? _noRetry(int retryCount, Object error) => null;
