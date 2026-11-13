// 1 件の募集について、この人から見た応募の状態（応募できる / 応募済み / 満員 / 応募できない）。
// 一覧のバッジ・行を押せるか、詳細のボタンの文言・押せるかを、この 1 つの値から決める。
//
// Step 7 から、API の値だけで決める（docs/01 の 7A.4。CLAUDE.md の絶対ルール 1）:
// 1. my_application（自分の応募）があれば「応募済み」。文言はその status.label（応募中・確定・却下など）をそのまま出す
// 2. can.apply が true なら「応募できる」
// 3. どちらでもなく、remaining が 0 なら「満員」、そうでなければ「応募できない」（締切・中止・勤務日が過ぎた など）
// 「応募できるか」の条件（募集中・今日以降・残り枠・自分の応募がない）は API がまとめて can.apply にしているので、
// アプリで組み立て直さない。満員かどうかも、表示の出し分けに remaining を見るだけで、最後の判定は API の 422。
//
// sealed class: https://dart.dev/language/class-modifiers#sealed
// パターン（オブジェクトのパターン）: https://dart.dev/language/pattern-types#object
// switch 式と、書き漏らしの検査（exhaustiveness）: https://dart.dev/language/branches#exhaustiveness-checking

import 'job_posting.dart';

// Step 3〜6 は enum（notApplied / applied / full）だった。「応募済み」のときだけ文言（label）を持たせたいので、
// sealed class に替えた。sealed = 「子のクラスはこのファイルの中にあるものだけ」という宣言。
// だから switch で子を全部書いたかをコンパイラが確かめられる（enum と同じく、書き漏らすとコンパイルエラー）。
// TypeScript で言うと、type ApplyStatus = { kind: 'applied'; label: string } | { kind: 'available' } | ...
// の判別可能なユニオン型に当たる。
sealed class ApplyStatus {
  const ApplyStatus();

  // 募集から状態を決める。上から順に当てはめ、最初に当たったものになる。
  // switch (job) { パターン => 値 }: job の形でふり分ける。
  // JobPosting(myApplication: final my?): 「myApplication が null でなければ、my という名前で取り出す」。
  //   final my? の ? は「null でないときだけ当たる」（null チェックのパターン）。
  // JobPosting(can: JobPostingCan(apply: true)): 入れ子の値が true のときだけ当たる。
  // _: どれにも当たらなかったとき（default）。
  static ApplyStatus of(JobPosting job) => switch (job) {
    JobPosting(myApplication: final my?) => ApplyApplied(label: my.status.label),
    JobPosting(can: JobPostingCan(apply: true)) => const ApplyAvailable(),
    JobPosting(remaining: 0) => const ApplyFull(),
    _ => const ApplyUnavailable(),
  };
}

// 応募できる（詳細のボタンは「応募する」）。
// final class: このファイルの外で継承させない（sealed の子をこれ以上増やさない）。
final class ApplyAvailable extends ApplyStatus {
  const ApplyAvailable();
}

// 応募済み。label は API の my_application.status.label（応募中・確定・却下・キャンセル）。
final class ApplyApplied extends ApplyStatus {
  const ApplyApplied({required this.label});

  final String label;
}

// 満員（応募がなく、残り枠が 0）。
final class ApplyFull extends ApplyStatus {
  const ApplyFull();
}

// 応募できない（応募がなく、残り枠もあるが can.apply が false。締切・中止・勤務日が過ぎた など）。
// 理由は API が返さないので、アプリで推し量らずに「応募できません」とだけ出す。
final class ApplyUnavailable extends ApplyStatus {
  const ApplyUnavailable();
}
