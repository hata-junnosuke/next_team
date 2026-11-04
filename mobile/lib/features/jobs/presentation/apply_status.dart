// 1 件の募集について、この人から見た応募の状態（未応募 / 応募済み / 満員）。
// 一覧のバッジ・行を押せるか、詳細のボタンの文言・押せるかを、この 1 つの値から決める。
// 前は「満員か」「応募済みか」の判定が一覧と詳細に散らばっていたので、ここに寄せた。
// Step 7 では、API の my_application（自分の応募）と can.apply（応募できるか）から決めるように替える。
//
// enum: https://dart.dev/language/enums
// switch 式と、書き漏らしの検査（exhaustiveness）: https://dart.dev/language/branches#exhaustiveness-checking
// sealed: https://dart.dev/language/class-modifiers#sealed

import '../domain/job_posting.dart';

// 3 つの状態は、どれもほかのデータを持たないので enum にする。
// 状態ごとに違うデータを持たせたいとき（例: 応募済みなら応募の id と状態の文言を持つ）は sealed class にする。
// sealed class も、switch で全部の種類を書かないとコンパイルエラーになる点は enum と同じ。
enum ApplyStatus {
  notApplied,
  applied,
  full;

  // 募集と「応募したか」から状態を決める。
  // 応募済みを満員より先に見る（自分が応募したあとで満員になっても、自分には「応募済み」と出す）。
  static ApplyStatus of(JobPosting job, {required bool applied}) {
    if (applied) {
      return ApplyStatus.applied;
    }
    if (job.remaining == 0) {
      return ApplyStatus.full;
    }
    return ApplyStatus.notApplied;
  }
}
