// 募集の詳細の画面。パスは /jobs/:id（例: /jobs/3）。
// Next.js で言うと app/jobs/[id]/page.tsx に当たる。下に「応募する」ボタンを置く。
// 応募は、まだどこにも保存しない（押すと「応募済み」に変わるだけ）。Step 7 で API の応募（POST）に替える。
//
// パスの引数: https://pub.dev/documentation/go_router/latest/topics/Configuration-topic.html
// 画面遷移の全体像: https://docs.flutter.dev/ui/navigation
// StatefulWidget と setState: https://docs.flutter.dev/ui/interactivity

import 'package:flutter/material.dart';

import '../../../core/format.dart';
import '../data/fake_job_repository.dart';

// 「応募したか」をこの画面の中で覚えるので Stateful にする（React の useState を持つコンポーネント）。
// StatefulWidget は 2 つのクラスの組になる:
// - JobDetailScreen（この class）: 親から受け取る値（props）だけを持つ。作り直されることがある
// - _JobDetailScreenState: 変わる値（state）を持つ。画面に出ている間は同じものが使われ続ける
class JobDetailScreen extends StatefulWidget {
  const JobDetailScreen({required this.jobId, super.key});

  // パスの :id。数でなかったら null（/jobs/abc など）。
  final int? jobId;

  // この Widget の状態を持つ State を作る。Flutter が 1 回だけ呼ぶ。
  @override
  State<JobDetailScreen> createState() => _JobDetailScreenState();
}

class _JobDetailScreenState extends State<JobDetailScreen> {
  // 応募したか。state なので final にしない（React の const [applied, setApplied] = useState(false)）。
  // 状態がこの画面の中にあるので、一覧に戻って詳細を開き直すと false に戻る（画面と一緒に捨てられるため）。
  // 一覧に戻っても残すのは、後のコミット（状態を親に持ち上げる）でやる。
  bool _applied = false;

  @override
  Widget build(BuildContext context) {
    // State の中では、親から受け取った値（props）を widget.xxx で読む。
    // jobId をいったんローカル変数に入れると、null を確かめたあとの型の絞り込みが効き、! が要らない
    // （クラスの公開のフィールドのままだと絞り込まれない。https://dart.dev/tools/non-promotion-reasons ）。
    final jobId = widget.jobId;
    // 条件 ? A : B は JavaScript と同じ三項演算子。
    final job = jobId == null
        ? null
        : const FakeJobRepository().findJobPosting(jobId);

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
            // setState: state を変えて「build をやり直して」と Flutter に伝える（React の setApplied(true)）。
            // _applied = true と書くだけでは画面は変わらない。必ず setState の中で変える。
            onPressed: _applied ? null : () => setState(() => _applied = true),
            child: Text(_applied ? '応募済み' : '応募する'),
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
