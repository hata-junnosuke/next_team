// 応募の取り下げを送る「mutation」と、その送り方（Step 8）。
// 作りは さがす の応募（ui/jobs/view_models/apply_mutation.dart）と同じ形。違いは 3 つだけ:
// - キーが募集の id ではなく、応募の id（よてい の 1 行 = 1 つの応募）
// - 送る先が POST /api/worker/applications/{id}/withdraw
// - 成功したあとに捨てる Provider（よてい と、さがす の詳細・一覧）
// React で言うと TanStack Query の useMutation。
//
// mutations: https://riverpod.dev/ja/docs/concepts2/mutations
// ref.invalidate: https://riverpod.dev/ja/docs/concepts2/refs

import 'package:flutter_riverpod/experimental/mutation.dart';

import '../../../config/dependencies.dart';
import '../../../data/services/api_error.dart';
import '../../../domain/models/worker_application.dart';
// さがす（jobs）の Provider。読んだ値は使わず、取り下げの後に invalidate するためだけに import する
// （機能をまたいで状態を共有しない。docs/01 の 7A.1。issue #51 の Q3）。
import '../../jobs/view_models/job_detail_provider.dart';
import '../../jobs/view_models/job_list_provider.dart';
import 'my_applications_provider.dart';

// 取り下げの mutation。応募の id をキーにして読む（withdrawMutation(application.id)）。
// 何を持つか: その応募の取り下げの送信の状態（待機 / 送信中 / 成功 / 失敗）。
// いつ元の待機に戻るか: 誰も watch しなくなったとき（その行が一覧から消えたときなど）。
final withdrawMutation = Mutation<WorkerApplication>(label: 'withdraw');

// 応募を取り下げる。よてい の「取り下げる」（確認ダイアログの後）から呼ぶ。
// 流れは applyToJobPosting と同じ:
// 1. 送信中か、もう成功していたら何もしない（2 回押しても 1 回しか送らない）
// 2. run の中で API に送る
// 3. 成功したら関係する Provider を捨てて取り直させる。422（その間に確定されたなど）と 404 のときも、画面の値が古いので取り直す
// 失敗したとき run は例外を投げ直すので、呼んだ側（画面）は受け止める。表示は mutation の状態（MutationError）で行う。
Future<void> withdrawApplication(
  MutationTarget target,
  WorkerApplication application,
) async {
  final mutation = withdrawMutation(application.id);
  if (target.container.read(mutation) case MutationPending() || MutationSuccess()) {
    return;
  }

  try {
    await mutation.run(target, (tsx) async {
      return tsx.get(jobRepositoryProvider).withdraw(application.id);
    });
    _refresh(target, application.jobPosting.id);
  } on ApiError catch (e) {
    if (e.statusCode == 422 || e.statusCode == 404) {
      _refresh(target, application.jobPosting.id);
    }
    rethrow;
  }
}

// 取り下げで変わる画面の値を捨てる。
// - よてい（両方のタブ）: 取り下げた予定が「応募中」から消える。422 で確定に移っていたときは「確定」に出る
// - その募集の詳細: 「取り下げ」と出て、応募のボタンが押せなくなる（API の my_application と can.apply）
// - さがす の一覧（すべての絞り込み）: 行のバッジが「取り下げ」に変わる
// 見えていない画面の Provider は、捨てるだけで取りには行かない（次に開いたときに取る）。
void _refresh(MutationTarget target, int jobPostingId) {
  target.container
    ..invalidate(myApplicationsProvider)
    ..invalidate(jobDetailProvider(jobPostingId))
    ..invalidate(jobListProvider);
}
