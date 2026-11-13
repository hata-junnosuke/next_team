// 募集の詳細の画面。パスは /jobs/:id（例: /jobs/3）。
// Next.js で言うと app/jobs/[id]/page.tsx に当たる。下に「応募する」ボタンを置く。
// ボタンの文言と押せるかは、API の my_application・can.apply・remaining から ApplyStatus.of で決める（Step 7）。
// 「応募する」で確認ダイアログ（apply_confirm_dialog.dart）を出し、「応募する」を選んだら
// POST /api/worker/job-postings/{id}/applications を送る（Step 7。apply_mutation.dart）。
// 成功したらスナックバーを出し、詳細と一覧は API から取り直す（「応募中」に変わる）。
// 失敗したら API の message（422 の「この募集にはすでに応募しています。」など）をボタンの上に出す。
// 募集の取得は jobDetailProvider(id) に任せ、この画面はリポジトリを直接読まない（issue #35 の T4。docs/01 の 7A.1）。
//
// パスの引数: https://pub.dev/documentation/go_router/latest/topics/Configuration-topic.html
// 画面遷移の全体像: https://docs.flutter.dev/ui/navigation
// ConsumerWidget: https://riverpod.dev/ja/docs/concepts2/consumers
// 引数つきの Provider（family）: https://riverpod.dev/ja/docs/concepts2/family
// ref.read と ref.watch の使い分け: https://riverpod.dev/ja/docs/concepts2/refs
// ref.listen（値が変わったときに 1 回だけ何かをする）: https://riverpod.dev/ja/docs/concepts2/refs
// mutations: https://riverpod.dev/ja/docs/concepts2/mutations
// スナックバー: https://docs.flutter.dev/cookbook/design/snackbars

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/experimental/mutation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../domain/models/apply_status.dart';
import '../../../domain/models/job_posting.dart';
import '../../../utils/format.dart';
import '../view_models/apply_mutation.dart';
import '../view_models/job_detail_provider.dart';
import 'apply_confirm_dialog.dart';

// 自分では状態を持たないので ConsumerWidget（Provider を読める StatelessWidget）にする。
// issue #35 の T4 の前は、募集の Future を 1 回だけ作って持ち続けるために ConsumerStatefulWidget にし、
// initState で Future を作って FutureBuilder で描き分けていた。
// 「1 回だけ取る」「取った結果を持ち続ける」は jobDetailProvider(id) が引き受けるので、State も initState も要らない
// （React で言うと、useState + useEffect の fetch を、useQuery 1 行に置き換えたのと同じ）。
class JobDetailScreen extends ConsumerWidget {
  const JobDetailScreen({required this.jobId, super.key});

  // パスの :id。数でなかったら null（/jobs/abc など）。
  final int? jobId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // jobId をいったんローカル変数に入れると、null を確かめたあとの型の絞り込みが効き、! が要らない
    // （クラスの公開のフィールドのままだと絞り込まれない。https://dart.dev/tools/non-promotion-reasons ）。
    final id = jobId;
    // id が数でないときは、取りに行くまでもなく「見つかりません」（Provider の引数は int で、null を渡せない）。
    if (id == null) {
      return _buildJob(context, ref, null);
    }

    // build の中で描く値なので ref.watch。読み込みが終わると、この build がやり直される。
    // 値は AsyncValue<JobPosting?>（読み込み中 / エラー / データ。一覧の jobListProvider と同じ）。
    // when で 3 つを全部書く。FutureBuilder の connectionState や hasError を自分で見なくてよい。
    return ref
        .watch(jobDetailProvider(id))
        .when(
          loading: () => Scaffold(
            appBar: AppBar(),
            body: const Center(child: CircularProgressIndicator()),
          ),
          // 読み込みに失敗したとき（API に届かない・500 など）。jobDetailProvider は自動でやり直さないので、
          // 「再読み込み」で invalidate して取り直す（一覧の画面と同じ）。
          // 応答の形が違う（FormatException）ときは、英語やキーの名前を出さずに決まりの文言にする。
          error: (error, _) => Scaffold(
            appBar: AppBar(),
            body: Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Text('募集を読み込めませんでした'),
                    const SizedBox(height: 4),
                    Text(
                      error is FormatException
                          ? '応答の形が想定と違います。管理者にお問い合わせください。'
                          : '$error',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 16),
                    FilledButton.icon(
                      onPressed: () => ref.invalidate(jobDetailProvider(id)),
                      icon: const Icon(Icons.refresh),
                      label: const Text('再読み込み'),
                    ),
                  ],
                ),
              ),
            ),
          ),
          // 届いた結果が null（見つからない）でも data に来る。null かどうかは _buildJob が見る。
          data: (job) => _buildJob(context, ref, job),
        );
  }

  // 届いた募集を描く。job が null なら「見つかりません」。
  Widget _buildJob(BuildContext context, WidgetRef ref, JobPosting? job) {
    // 見つからないとき（API なら 404）。
    // AppBar を置くと、親のルート（一覧）があるので、戻るボタンが自動で出る。
    if (job == null) {
      return Scaffold(
        appBar: AppBar(),
        body: const Center(child: Text('募集が見つかりません')),
      );
    }

    // ここから下では job は null でないと Dart が分かっている（上の if で return したため）。
    // これを「型の絞り込み（promotion）」と言う。TypeScript の if (!job) return と同じ。

    // ボタンの出し分けに使う状態。API の値（my_application・can.apply・remaining）だけで決まる。
    final status = ApplyStatus.of(job);

    // この募集の応募の送信の状態（待機 / 送信中 / 成功 / 失敗）。変わるとこの build がやり直される。
    final applying = ref.watch(applyMutation(job.id));

    // ref.listen: 値が変わったときに 1 回だけ処理をする（描き直しではなく、スナックバーのような「1 回きり」の表示に使う）。
    // React で言うと useEffect(() => { if (isSuccess) toast(...) }, [isSuccess])。
    // 成功に変わったときにだけ出す。詳細と一覧の取り直しは applyToJobPosting が済ませている。
    // ScaffoldMessenger: 画面をまたいでスナックバーを出す係（アプリの外枠にある）。一覧に戻っても少しの間残る。
    ref.listen(applyMutation(job.id), (previous, next) {
      if (next is MutationSuccess) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('応募しました')));
      }
    });

    return Scaffold(
      appBar: AppBar(title: Text(job.jobTitle)),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _DetailRow(label: '勤務日', value: formatDate(job.workDate)),
          _DetailRow(
            label: '時間',
            value: formatTimeRange(job.startTime, job.endTime),
          ),
          _DetailRow(label: '拠点', value: job.location.name),
          _DetailRow(label: '時給', value: formatYen(job.hourlyWage)),
          _DetailRow(label: '交通費', value: formatYen(job.transportAllowance)),
          _DetailRow(
            label: '残り枠',
            value: '${job.remaining}枠（定員 ${job.capacity}人）',
          ),
        ],
      ),
      // 画面の下に固定するボタン。この Scaffold は下タブの内側にあるので、ボタンは下タブのすぐ上に出る。
      // SafeArea: 端末の角の丸みやホームバーと重ならないように内側へ寄せる。
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // 失敗したときだけ、ボタンの上に理由を出す。
              // if (x case パターン): x がパターンに当たるときだけ要素を入れる（if-case。値の取り出しもできる）。
              // MutationError(:final error): 失敗なら、その例外を error として取り出す。
              if (applying case MutationError(:final error))
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Text(
                    _applyErrorMessage(error),
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ),
              _buildApplyButton(context, ref, job, status, applying),
            ],
          ),
        ),
      ),
    );
  }

  // 「応募する」のボタン。押せるのは、API の can.apply が true（ApplyAvailable）で、送信中でないときだけ。
  // 最後の判定は API の 422 に任せる（ボタンが押せても、その間に満員になっていれば 422 の文言が出る）。
  Widget _buildApplyButton(
    BuildContext context,
    WidgetRef ref,
    JobPosting job,
    ApplyStatus status,
    MutationState<Object?> applying,
  ) {
    final isSending = applying is MutationPending;
    // FilledButton: 塗りつぶしのボタン（いちばん目立たせたい操作に使う）。
    return FilledButton(
      // onPressed に null を渡すと、ボタンは押せない見た目（灰色）になる（HTML の disabled）。
      // 送信中も null にして、2 回押しても 1 回しか送らない（applyToJobPosting の中でも確かめている）。
      onPressed: status is ApplyAvailable && !isSending
          ? () => _apply(context, ref, job)
          : null,
      // switch 式で状態ごとの文言を返す。ApplyStatus は sealed なので、子を 1 つでも書き漏らすと
      // コンパイルエラーになる（状態を足したときに、文言を決め忘れた所を全部コンパイラが教えてくれる）。
      // 「応募済み」の文言は API の my_application.status.label（応募中・確定など）をそのまま出す。
      // 送信中はくるくるを出す（ログインのボタンと同じ）。
      child: isSending
          ? const SizedBox.square(
              dimension: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            )
          : Text(switch (status) {
              ApplyAvailable() => '応募する',
              ApplyApplied(:final label) => label,
              ApplyFull() => '満員',
              ApplyUnavailable() => '応募できません',
            }),
    );
  }

  // 押したら、まず確認ダイアログを出す。「やめる」なら何もしない（API に送らない）。
  // 「応募する」なら応募を送る。結果（成功・失敗）の表示は mutation の状態が受け持つので、ここでは例外を受け止めるだけ。
  // 受け止めないと「誰も受け取らなかった例外」になる。Exception（通信・422・形の違い）だけを受け、
  // Error（プログラムのバグ）は受けずに表に出す（job_list_screen.dart の _refresh と同じ考え方）。
  Future<void> _apply(
    BuildContext context,
    WidgetRef ref,
    JobPosting job,
  ) async {
    if (!await showApplyConfirmDialog(context, job)) return;
    // ダイアログを開いている間に、この画面が閉じられていることがある（戻るボタンなど）。
    // context.mounted: この Widget がまだ画面にあるか。なければ ref も使えないので、送らずに終わる。
    if (!context.mounted) return;
    try {
      await applyToJobPosting(ref, job.id);
    } on Exception {
      // 表示は MutationError に任せる。
    }
  }

  // 失敗の文言。API の message（ApiError の toString()）か、接続できないときの文言をそのまま出す。
  // アプリで「満員です」などの文言を作らない（7A.4）。形が違うときだけ決まりの文言にする。
  static String _applyErrorMessage(Object error) =>
      error is FormatException ? '応答の形が想定と違います。管理者にお問い合わせください。' : '$error';
}

// 「見出し: 値」の 1 行。この画面でしか使わないので、同じファイルに _ を付けて置く（ファイルの外から見えない）。
// React で言うと、ファイルの中だけで使う小さなコンポーネント。
class _DetailRow extends StatelessWidget {
  const _DetailRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    // Padding で上下に余白を付け、Row で横に並べる（CSS の flex-direction: row）。
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        children: [
          // SizedBox で見出しの幅をそろえ、値の頭の位置を合わせる。
          SizedBox(
            width: 80,
            child: Text(label, style: Theme.of(context).textTheme.labelLarge),
          ),
          // Expanded: 残りの幅を全部使う（CSS の flex: 1）。長い値でもはみ出さずに折り返す。
          Expanded(child: Text(value)),
        ],
      ),
    );
  }
}
