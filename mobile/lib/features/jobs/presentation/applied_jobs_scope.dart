// 「どの募集に応募したか」を、一覧と詳細の両方から読み書きできるようにする仕組み。
// 状態（AppliedJobs）はアプリの外枠（app.dart の WorkerApp）が持ち、AppliedJobsScope で下の画面に配る。
// React で言うと、App で useState した値を <AppliedJobsContext.Provider> で配り、useContext で読むのと同じ。
//
// なぜ外枠まで持ち上げたか: 一覧（/jobs）と詳細（/jobs/:id）は go_router の builder の中で別々に作られ、
// 私たちが書いた Widget の中で親子として出会う場所がない。共通の親は、ルーターより上のアプリ全体だけ。
// そこから画面までは引数（props）で渡せないので、祖先から直接読める InheritedNotifier を使う。
// Step 4 で Riverpod に替えると、このファイルと app.dart の持ち上げがまるごと要らなくなる。
// まだ保存しないので、アプリを再起動すると消える（Step 7 で API の応募に替える）。
//
// 状態を親に持ち上げる: https://docs.flutter.dev/data-and-backend/state-mgmt/simple
// ChangeNotifier: https://api.flutter.dev/flutter/foundation/ChangeNotifier-class.html
// InheritedNotifier: https://api.flutter.dev/flutter/widgets/InheritedNotifier-class.html

import 'package:flutter/material.dart';

// 応募した募集の id の集まり。値が変わったら notifyListeners() で「変わった」と知らせる。
// ChangeNotifier = 変化を知らせる仕組みを持ったクラス（JavaScript の EventEmitter に近い）。
class AppliedJobs extends ChangeNotifier {
  // Set: 同じ値を 2 回入れても 1 つだけ持つ集まり（JavaScript の Set、Ruby の Set）。
  // {} は空の Set（型を <int> で書いているので Map ではなく Set になる）。
  final Set<int> _ids = <int>{};

  bool contains(int jobId) => _ids.contains(jobId);

  void apply(int jobId) {
    _ids.add(jobId);
    // これを呼ぶと、AppliedJobsScope.of(context) で読んでいる画面の build がやり直される。
    // setState と違い、どの画面からでも呼べる。
    notifyListeners();
  }
}

// AppliedJobs を下の Widget に配る Widget（React の Context.Provider）。
// InheritedNotifier は、notifier（ここでは AppliedJobs）が知らせると、of(context) で読んでいる Widget を描き直す。
class AppliedJobsScope extends InheritedNotifier<AppliedJobs> {
  const AppliedJobsScope({
    required AppliedJobs appliedJobs,
    required super.child,
    super.key,
  }) : super(notifier: appliedJobs);

  // 画面から AppliedJobs を取り出す（React の useContext(AppliedJobsContext)）。
  // dependOnInheritedWidgetOfExactType: 祖先から AppliedJobsScope を探し、変化を購読する。
  // static: インスタンスを作らずに AppliedJobsScope.of(context) と呼べるメソッド（Ruby のクラスメソッド）。
  static AppliedJobs of(BuildContext context) {
    final scope = context
        .dependOnInheritedWidgetOfExactType<AppliedJobsScope>();
    // 祖先に AppliedJobsScope がない（app.dart で包み忘れた）なら、その場で気づけるように落とす。
    assert(scope != null, 'AppliedJobsScope が祖先にありません（app.dart で包む）');
    return scope!.notifier!;
  }
}
