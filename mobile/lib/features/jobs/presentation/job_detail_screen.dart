// 募集の詳細の画面。パスは /jobs/:id（例: /jobs/3）。
// Next.js で言うと app/jobs/[id]/page.tsx に当たる。下に「応募する」ボタンを置く。
// 応募は、まだどこにも保存しない（押すと「応募済み」に変わるだけ）。Step 7 で API の応募（POST）に替える。
// 「応募済み」は appliedJobsProvider に入れるので、一覧に戻っても、開き直しても残る。
//
// パスの引数: https://pub.dev/documentation/go_router/latest/topics/Configuration-topic.html
// 画面遷移の全体像: https://docs.flutter.dev/ui/navigation
// StatefulWidget と setState: https://docs.flutter.dev/ui/interactivity
// initState: https://api.flutter.dev/flutter/widgets/State/initState.html
// ConsumerStatefulWidget: https://riverpod.dev/ja/docs/concepts2/consumers
// FutureBuilder: https://api.flutter.dev/flutter/widgets/FutureBuilder-class.html
// ref.read と ref.watch の使い分け: https://riverpod.dev/ja/docs/concepts2/refs

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/format.dart';
import '../application/applied_jobs_provider.dart';
import '../data/job_repository.dart';
import '../domain/apply_status.dart';
import '../domain/job_posting.dart';

// 募集の Future を 1 回だけ作って持ち続けるので Stateful にし、リポジトリを Provider から受け取るので
// Consumer にする。2 つを合わせたのが ConsumerStatefulWidget（Provider を読める StatefulWidget）。
// State の側は ConsumerState を継承し、ref をフィールドとして持つ（ConsumerWidget のように build の引数ではない）。
// だから build だけでなく、initState やほかのメソッドからも ref が使える。
// 一覧の画面は T7 で State が要らなくなり ConsumerWidget にしたが、この画面は Future を State に持つので残る。
// 「応募したか」はこの State には持たない。画面と一緒に捨てられて一覧に戻ると消えるので、
// appliedJobsProvider（keepAlive）に置いている。
// StatefulWidget は 2 つのクラスの組になる:
// - JobDetailScreen（この class）: 親から受け取る値（props）だけを持つ。作り直されることがある
// - _JobDetailScreenState: 変わる値（state）を持つ。画面に出ている間は同じものが使われ続ける
class JobDetailScreen extends ConsumerStatefulWidget {
  const JobDetailScreen({required this.jobId, super.key});

  // パスの :id。数でなかったら null（/jobs/abc など）。
  final int? jobId;

  // この Widget の状態を持つ State を作る。Widget がツリーに入るときに Flutter が呼ぶ。
  // 同じ場所にある間は、できた State が使い回される（build のたびには呼ばれない）。
  @override
  ConsumerState<JobDetailScreen> createState() => _JobDetailScreenState();
}

class _JobDetailScreenState extends ConsumerState<JobDetailScreen> {
  // 募集が届く Future。build のたびに読み込み直さないように State に持つ。
  // build の中で findJobPosting() を呼ぶと、描き直すたびに読み込み直してしまう
  // （React で useEffect の外で fetch するのと同じ失敗）。
  // late: 「宣言の時点ではまだ値を入れず、使う前に必ず入れる」印。値は下の initState で 1 回だけ入れる。
  // final なので、一度入れたら入れ替えられない。
  late final Future<JobPosting?> _jobFuture;

  // initState: この State が作られた直後に 1 回だけ呼ばれる（React の useEffect(() => {...}, [])）。
  // build より前に呼ばれ、2 回目の build からは呼ばれない。1 回だけ読み込みたいので、ここで Future を作る。
  // super.initState() は、親クラス（State）の initState も忘れずに呼ぶための決まり文句。
  @override
  void initState() {
    super.initState();
    _jobFuture = _loadJob();
  }

  Future<JobPosting?> _loadJob() async {
    // State の中では、親から受け取った値（props）を widget.xxx で読む。
    // jobId をいったんローカル変数に入れると、null を確かめたあとの型の絞り込みが効き、! が要らない
    // （クラスの公開のフィールドのままだと絞り込まれない。https://dart.dev/tools/non-promotion-reasons ）。
    final jobId = widget.jobId;
    if (jobId == null) {
      return null;
    }
    // ref.read: 今の値を 1 回だけ読む（変化は購読しない）。
    // ここは build ではなく、initState から 1 回だけ呼ばれる所なので read にする。
    // build の中で値を描くときは ref.watch、ボタンを押したときや 1 回きりの処理では ref.read。
    // 相手が偽物か API かは jobRepositoryProvider が決めるので、この画面は知らない。
    return ref.read(jobRepositoryProvider).findJobPosting(jobId);
  }

  @override
  Widget build(BuildContext context) {
    // FutureBuilder: Future の「待っている / 失敗した / 届いた」に合わせて描き分ける Widget。
    // Future が終わると、自動で builder が呼ばれ直す（React で useEffect + useState で書くことを 1 つにしたもの）。
    // 一覧の画面は T3 で Provider の AsyncValue.when に替えた。詳細は「1 件をパスの id で取る」ので、
    // Provider にするなら引数つき（family。T7 の jobListProvider(filter) と同じ形）になる。Step 7 で API の
    // 詳細（GET /api/worker/job-postings/{id}）につなぐときに Provider に移す。
    return FutureBuilder<JobPosting?>(
      future: _jobFuture,
      // snapshot: Future の今の様子（data に届いた値、error に例外、connectionState に終わったかが入る）。
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
