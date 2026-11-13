// 募集の詳細（1 件）を持つ Provider。詳細の画面はここから読むだけで、リポジトリを直接読まない（docs/01 の 7A.1）。
// React で言うと useQuery({ queryKey: ['job', id], queryFn: () => fetchJob(id) }) を画面の外に書いたもの。
// Next.js で言うと、app/jobs/[id]/page.tsx の中で params.id を使って取ってくる部分だけを切り出したもの。
//
// 引数つきの Provider（family）: https://riverpod.dev/ja/docs/concepts2/family
// 自動で捨てる（auto dispose）: https://riverpod.dev/ja/docs/concepts2/auto_dispose

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../config/dependencies.dart';
import '../../../domain/models/job_posting.dart';

part 'job_detail_provider.g.dart';

// id で 1 件。見つからなければ null（API なら 404）。
// jobListProvider(filter) と同じ引数つきの Provider で、画面からは jobDetailProvider(3) のように読む。
// id ごとに別の Provider になるので、/jobs/3 と /jobs/5 の結果が混ざらない
// （issue #35 の T4 の前は、画面の State に Future を持ち、router.dart の key で id ごとに State を作り直していた）。
//
// 何を持つか: その id の募集（null なら見つからない）。id ごとに 1 つずつ。
// いつ作られるか: 詳細の画面が初めて ref.watch したとき。
// いつ作り直されるか:
// - 中で ref.watch している jobRepositoryProvider が変わったとき（Step 7 で API に差し替えても、ここはそのまま）
// - keepAlive を付けていないので、詳細の画面を閉じて誰も読まなくなると捨てられ、
//   もう一度開くと取り直す（前は画面の State と一緒に捨てていたので、動きは issue #35 の T4 の前と同じ）
// - ref.invalidate(jobDetailProvider(id)) されたとき（応募の後に取り直す。T5）
//
// retry: 自動でやり直さない（Step 7。jobListProvider と同じ）。既定のままだと、API が止まっているときに
// 最大 10 回やり直す間ずっと読み込み中（くるくる）が出て、エラーと「再読み込み」がなかなか出ない。
// 通信の切断はやり直せば直ることもあるが、いつ直るかは分からないので、やり直すかは人が「再読み込み」で決める。
// 見つからない（404）は失敗ではなく null なので、そもそもやり直しの対象にならない。
@Riverpod(retry: _noRetry)
Future<JobPosting?> jobDetail(Ref ref, int id) {
  return ref.watch(jobRepositoryProvider).findJobPosting(id);
}

// 自動でやり直さない、という retry の関数（job_list_provider.dart と同じ）。
Duration? _noRetry(int retryCount, Object error) => null;
