// 募集への応募を送る「mutation」と、その送り方（Step 7。docs/01 の 7A.1 の Q4）。
// mutation = 「送信中 / 成功 / 失敗」を持つ、副作用（フォームの送信など）のための Riverpod の状態。
// React で言うと、TanStack Query の useMutation（isPending / isError / mutate）に当たる。
// 画面は applyMutation(募集の id) を ref.watch して、送信中ならボタンを押せなくし、失敗なら文言を出す。
//
// Riverpod の mutations は試験的な機能（experimental）で、今後の版で形が変わることがある（issue #48 の Q4 で承知の上で選んだ）。
// Step 6 のログインは Notifier に送信中・失敗を自分で持たせた（login_view_model.dart）。比べると、
// mutation は「送信中・成功・失敗」の 3 つと、それを募集ごとに分ける仕組みを Riverpod が用意してくれる。
//
// mutations: https://riverpod.dev/ja/docs/concepts2/mutations
// ref.invalidate: https://riverpod.dev/ja/docs/concepts2/refs

import 'package:flutter_riverpod/experimental/mutation.dart';

import '../../../config/dependencies.dart';
import '../../../data/services/api_error.dart';
import '../../../domain/models/worker_application.dart';
import 'job_detail_provider.dart';
import 'job_list_provider.dart';

// 応募の mutation。トップレベルの final（アプリに 1 つ）として置き、Provider と同じように画面から watch する。
// Mutation<WorkerApplication>: 成功したときの値の型（API が返した自分の応募）。
// 画面からは applyMutation(job.id) と、募集の id を「キー」として渡して読む。キーごとに別々の状態になるので、
// 募集 3 の送信中に募集 5 の詳細を開いても、5 のボタンは「送信中」にならない（family の Provider と同じ考え方）。
//
// 何を持つか: その募集の応募の送信の状態（MutationIdle 待機 / MutationPending 送信中 / MutationSuccess 成功 / MutationError 失敗）。
// いつ作り直されるか（元の待機に戻るか）: 誰も watch しなくなったとき（詳細の画面を閉じたとき）。
// だから詳細を開き直すと、前の失敗の文言は消えている。
final applyMutation = Mutation<WorkerApplication>(label: 'apply');

// 募集に応募する。詳細の画面の「応募する」から呼ぶ。
// MutationTarget: mutation を動かせる相手（画面の WidgetRef、Provider の Ref など）。画面は自分の ref を渡す。
//
// 流れ:
// 1. 送信中か、もう成功していたら何もしない（2 回押しても 1 回しか送らない。ボタンも押せなくしているが、念のため）
// 2. applyMutation(id).run(...) の中で API に送る。run は始まると Pending、終わると Success / Error にする
// 3. 成功したら、その募集の詳細と、一覧のすべての絞り込みを invalidate して API から取り直す
//    422（二重応募・満員・締切など）と 404（募集が見えなくなった）のときも、画面の値が古いので取り直す
//    （応答で手元の値を書き換えない。残り枠をアプリで計算することになるため。7A.4 の「残り枠」）
//
// 失敗したとき run は例外を投げ直すので、呼んだ側（画面）は受け止める。失敗の表示は mutation の状態（MutationError）で行う。
Future<void> applyToJobPosting(MutationTarget target, int jobPostingId) async {
  final mutation = applyMutation(jobPostingId);
  // target.container: Provider の値の置き場（ProviderContainer）。画面の外から読み書きするときの入口。
  // read は今の値を 1 回読むだけ（変化は購読しない）。
  // 成功（MutationSuccess）も止めるのは、取り直しが届くまでの間、詳細は前の値（can.apply が true）を見せたままで、
  // ボタンをもう一度押せてしまうため。mutation は詳細を閉じると待機に戻るので、開き直せば元どおり。
  // ||: 「または」のパターン（どちらかに当たれば true）。
  if (target.container.read(mutation) case MutationPending() || MutationSuccess()) {
    return;
  }

  try {
    // run のコールバックは tsx（MutationTransaction）を受け取る。tsx.get で Provider を読むと、
    // 送信が終わるまでその Provider が捨てられない（途中で画面を閉じても、リポジトリが消えない）。
    await mutation.run(target, (tsx) async {
      return tsx.get(jobRepositoryProvider).apply(jobPostingId);
    });
    _refresh(target, jobPostingId);
  } on ApiError catch (e) {
    // 422（二重応募・満員・締切など）と 404（中止などで見られなくなった）なら、画面の値が古いので取り直す
    // （詳細は「応募中」「満員」や「募集が見つかりません」に変わる）。
    // 通信の失敗（statusCode が null）などは取り直しても失敗するだけなので、取り直さない。
    if (e.statusCode == 422 || e.statusCode == 404) {
      _refresh(target, jobPostingId);
    }
    // rethrow: 受けた例外をそのまま投げ直す。表示は mutation の MutationError が受け持ち、画面は受け止めるだけ。
    rethrow;
  }
}

// その募集の詳細と、一覧（すべての絞り込み）を捨てて、次に読まれたときに API から取り直させる。
// jobListProvider は引数（日付のチップ, 拠点）つきの Provider。引数を付けずに invalidate すると、
// どの組（「今日」×「渋谷店」など）で読んでいたものも全部捨てる（React の queryClient.invalidateQueries({ queryKey: ['jobs'] })）。
void _refresh(MutationTarget target, int jobPostingId) {
  target.container
    // .. はカスケード: 同じ相手に続けてメソッドを呼ぶ（Ruby の tap、JavaScript のメソッドチェーンに近い）。
    ..invalidate(jobDetailProvider(jobPostingId))
    ..invalidate(jobListProvider);
}
