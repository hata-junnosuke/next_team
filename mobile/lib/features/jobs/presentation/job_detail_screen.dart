// 募集の詳細の画面。パスは /jobs/:id（例: /jobs/3）。
// Next.js で言うと app/jobs/[id]/page.tsx に当たる。応募ボタンは次のコミットで足す。
//
// パスの引数: https://pub.dev/documentation/go_router/latest/topics/Configuration-topic.html
// 画面遷移の全体像: https://docs.flutter.dev/ui/navigation

import 'package:flutter/material.dart';

import '../../../core/format.dart';
import '../data/fake_job_repository.dart';

// 今は受け取った id の募集を出すだけなので Stateless にする（応募ボタンのコミットで Stateful に変える）。
class JobDetailScreen extends StatelessWidget {
  const JobDetailScreen({required this.jobId, super.key});

  // パスの :id。数でなかったら null（/jobs/abc など）。
  final int? jobId;

  @override
  Widget build(BuildContext context) {
    // jobId が null なら探さない（条件 ? A : B は JavaScript と同じ三項演算子）。
    // jobId! の ! が要るのは、jobId がクラスの公開のフィールドだから。サブクラスが getter で
    // 上書きすると読むたびに違う値を返せるので、null を確かめても Dart は型を絞り込まない
    // （ローカル変数と、_ の付いた final のフィールドなら絞り込む）。https://dart.dev/tools/non-promotion-reasons
    final job = jobId == null
        ? null
        : const FakeJobRepository().findJobPosting(jobId!);

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
