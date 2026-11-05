// 募集の詳細の画面。パスは /jobs/:id（例: /jobs/3）。
// Next.js で言うと app/jobs/[id]/page.tsx に当たる。下に「応募する」ボタンを置く。
// 応募は、まだどこにも保存しない（押すと「応募済み」に変わるだけ）。Step 7 で API の応募（POST）に替える。
// 「応募済み」はアプリの外枠が持つ AppliedJobs に入れるので、一覧に戻っても、開き直しても残る。
//
// パスの引数: https://pub.dev/documentation/go_router/latest/topics/Configuration-topic.html
// 画面遷移の全体像: https://docs.flutter.dev/ui/navigation
// StatefulWidget と setState: https://docs.flutter.dev/ui/interactivity
// FutureBuilder: https://api.flutter.dev/flutter/widgets/FutureBuilder-class.html

import 'package:flutter/material.dart';

import '../../../core/format.dart';
import '../data/fake_job_repository.dart';
import '../domain/job_posting.dart';
import 'applied_jobs_scope.dart';
import 'apply_status.dart';

// 募集の Future を 1 回だけ作って持ち続けるので Stateful にする（React の useState を持つコンポーネント）。
// 「応募したか」はこの State には持たない。画面と一緒に捨てられて一覧に戻ると消えるので、
// アプリの外枠（AppliedJobs）に置いている。
// StatefulWidget は 2 つのクラスの組になる:
// - JobDetailScreen（この class）: 親から受け取る値（props）だけを持つ。作り直されることがある
// - _JobDetailScreenState: 変わる値（state）を持つ。画面に出ている間は同じものが使われ続ける
class JobDetailScreen extends StatefulWidget {
  const JobDetailScreen({required this.jobId, super.key});

  // パスの :id。数でなかったら null（/jobs/abc など）。
  final int? jobId;

  // この Widget の状態を持つ State を作る。Widget がツリーに入るときに Flutter が呼ぶ。
  // 同じ場所にある間は、できた State が使い回される（build のたびには呼ばれない）。
  @override
  State<JobDetailScreen> createState() => _JobDetailScreenState();
}

class _JobDetailScreenState extends State<JobDetailScreen> {
  // 募集が届く Future。一覧と同じく、build のたびに読み込み直さないように State に持つ（late は一覧の画面を参照）。
  late final Future<JobPosting?> _jobFuture = _loadJob();

  Future<JobPosting?> _loadJob() async {
    // State の中では、親から受け取った値（props）を widget.xxx で読む。
    // jobId をいったんローカル変数に入れると、null を確かめたあとの型の絞り込みが効き、! が要らない
    // （クラスの公開のフィールドのままだと絞り込まれない。https://dart.dev/tools/non-promotion-reasons ）。
    final jobId = widget.jobId;
    if (jobId == null) {
      return null;
    }
    return const FakeJobRepository().findJobPosting(jobId);
  }

  @override
  Widget build(BuildContext context) {
    // 使い方は一覧の画面の FutureBuilder と同じ。
    return FutureBuilder<JobPosting?>(
      future: _jobFuture,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return Scaffold(
            appBar: AppBar(),
            body: Center(child: Text('募集を読み込めませんでした\n${snapshot.error}')),
          );
        }
        // 読み込み中。届いた結果が null（見つからない）と見分けるため、connectionState で「終わったか」を見る。
        if (snapshot.connectionState != ConnectionState.done) {
          return Scaffold(
            appBar: AppBar(),
            body: const Center(child: CircularProgressIndicator()),
          );
        }
        return _buildJob(context, snapshot.data);
      },
    );
  }

  // 届いた募集を描く。job が null なら「見つかりません」。
  Widget _buildJob(BuildContext context, JobPosting? job) {
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

    // 外枠の AppliedJobs を読む。誰かが apply() を呼ぶと、この画面の build がやり直される。
    final appliedJobs = AppliedJobsScope.of(context);
    final status = ApplyStatus.of(job, applied: appliedJobs.contains(job.id));

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
            // 押したら外枠の AppliedJobs に入れる。setState は要らない（AppliedJobs が知らせて描き直させる）。
            // 押せるのは未応募のときだけ。満員は一覧からは入れないが、/jobs/4 を直に開いたとき用。
            // Step 7 では、押せるかどうかを API の can.apply で決め、最後の判定は API の 422 に任せる。
            onPressed: status == ApplyStatus.notApplied
                ? () => appliedJobs.apply(job.id)
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
