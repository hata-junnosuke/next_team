// 募集一覧のデータを持つ Provider。一覧の画面はここから読むだけで、自分では取りに行かない。
// React で言うと useQuery({ queryKey: ['jobs'], queryFn: fetchJobs }) の queryFn とキャッシュを、
// 画面の外に書いたものに当たる。
//
// Provider とは: https://riverpod.dev/ja/docs/concepts2/providers
// ref.watch / ref.read / ref.invalidate: https://riverpod.dev/ja/docs/concepts2/refs

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
@riverpod
Future<List<JobPosting>> jobList(Ref ref) {
  // ref.watch: ほかの Provider の値を読み、それが変わったらこの Provider も作り直す（React の useEffect の依存配列に近い）。
  final repository = ref.watch(jobRepositoryProvider);
  return repository.fetchJobPostings();
}
