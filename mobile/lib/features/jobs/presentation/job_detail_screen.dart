// 募集の詳細の画面。パスは /jobs/:id（例: /jobs/3）。
// Next.js で言うと app/jobs/[id]/page.tsx に当たる。下に「応募する」ボタンを置く。
// 応募は、まだどこにも保存しない（押すと「応募済み」に変わるだけ）。Step 7 で API の応募（POST）に替える。
// 「応募済み」は appliedJobsProvider に入れるので、一覧に戻っても、開き直しても残る。
// 募集の取得は jobDetailProvider(id) に任せ、この画面はリポジトリを直接読まない（issue #35 の T4。docs/01 の 7A.1）。
//
// パスの引数: https://pub.dev/documentation/go_router/latest/topics/Configuration-topic.html
// 画面遷移の全体像: https://docs.flutter.dev/ui/navigation
// ConsumerWidget: https://riverpod.dev/ja/docs/concepts2/consumers
// 引数つきの Provider（family）: https://riverpod.dev/ja/docs/concepts2/family
// ref.read と ref.watch の使い分け: https://riverpod.dev/ja/docs/concepts2/refs

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/format.dart';
import '../application/applied_jobs_provider.dart';
import '../application/job_detail_provider.dart';
import '../domain/apply_status.dart';
import '../domain/job_posting.dart';

// 自分では状態を持たないので ConsumerWidget（Provider を読める StatelessWidget）にする。
// issue #35 の T4 の前は、募集の Future を 1 回だけ作って持ち続けるために ConsumerStatefulWidget にし、
// initState で Future を作って FutureBuilder で描き分けていた。
// 「1 回だけ取る」「取った結果を持ち続ける」は jobDetailProvider(id) が引き受けるので、State も initState も要らない
// （React で言うと、useState + useEffect の fetch を、useQuery 1 行に置き換えたのと同じ）。
// 「応募したか」も、画面と一緒に捨てられないように appliedJobsProvider（keepAlive）に置いている。
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
          error: (error, _) => Scaffold(
            appBar: AppBar(),
            body: Center(child: Text('募集を読み込めませんでした\n$error')),
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

    // build の中で描く値なので ref.watch。apply() で state が入れ替わると、この画面の build がやり直される。
    final status = ApplyStatus.of(
      job,
      applied: ref.watch(appliedJobsProvider).contains(job.id),
    );

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
          // FilledButton: 塗りつぶしのボタン（いちばん目立たせたい操作に使う）。
          child: FilledButton(
            // onPressed に null を渡すと、ボタンは押せない見た目（灰色）になる（HTML の disabled）。
            // 押したら appliedJobsProvider の apply() を呼ぶ。setState は要らない（state が入れ替わると描き直される）。
            // .notifier: Provider の値（Set<int>）ではなく、Notifier そのもの（AppliedJobs のインスタンス）を取る。
            // メソッドを呼びたいときはこちら。押した瞬間に 1 回呼ぶだけなので watch ではなく read。
            // 押せるのは未応募のときだけ。満員は一覧からは入れないが、/jobs/4 を直に開いたとき用。
            // Step 7 では、押せるかどうかを API の can.apply で決め、最後の判定は API の 422 に任せる。
            onPressed: status == ApplyStatus.notApplied
                ? () => ref.read(appliedJobsProvider.notifier).apply(job.id)
                : null,
            // switch 式で状態ごとの文言を返す。ApplyStatus の値を 1 つでも書き漏らすとコンパイルエラーになる
            // （状態を足したときに、文言を決め忘れた所を全部コンパイラが教えてくれる）。
            // 値の前の . は「ApplyStatus.」の省略（switch の対象の型から分かるので書かなくてよい）。
            child: Text(switch (status) {
              .notApplied => '応募する',
              .applied => '応募済み',
              .full => '満員',
            }),
          ),
        ),
      ),
    );
  }
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
