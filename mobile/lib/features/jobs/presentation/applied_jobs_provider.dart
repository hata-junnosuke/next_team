// 「どの募集に応募したか」を持つ Provider。一覧と詳細の両方から読み書きする。
// Step 3 では ChangeNotifier と InheritedNotifier で、状態をアプリの外枠（app.dart）まで持ち上げていた。
// Provider は Widget ツリーの外に置けるので、持ち上げる先を探さなくてよい。どの画面からでも同じ名前で読める。
// React で言うと、Context ＋ useReducer（または Zustand の store）を、コンポーネントの外に書いたものに当たる。
// まだ保存しないので、アプリを再起動すると消える（Step 7 で API の応募と my_application に替える）。
//
// Notifier: https://riverpod.dev/ja/docs/concepts2/providers
// ref.watch / ref.read: https://riverpod.dev/ja/docs/concepts2/refs

import 'package:riverpod_annotation/riverpod_annotation.dart';

part 'applied_jobs_provider.g.dart';

// クラスに @riverpod を付けると Notifier になる = 「値」と「値を変えるメソッド」を一緒に持つ Provider。
// 関数の @riverpod（jobListProvider）は値を作るだけで、外から変えられない。外から変えたいときはクラスにする。
// extends _$AppliedJobs: 生成されたクラス（applied_jobs_provider.g.dart の中）を継承する。state や ref はそこから来る。
// 生成される Provider の名前は、クラス名の先頭を小文字にして Provider を付けた appliedJobsProvider。
//
// 何を持つか: 応募した募集の id の集まり（Set<int>）。
// いつ作られるか: 最初に誰かが読んだとき（build() の戻り値が最初の値になる）。
// いつ作り直されるか: 作り直さない。keepAlive: true なので、読む画面がなくなっても捨てずに持ち続ける
// （捨てると「応募済み」が消える）。中身が変わるのは apply() で state を置き換えたときだけ。
@Riverpod(keepAlive: true)
class AppliedJobs extends _$AppliedJobs {
  // 最初の値。React の useState(new Set()) の引数に当たる。
  @override
  Set<int> build() => <int>{};

  void apply(int jobId) {
    // state = 今の値。代入すると、この Provider を ref.watch している画面の build がやり直される。
    // state.add(jobId) と中身を書き換えるのではなく、新しい Set を作って入れ替える（イミュータブルな更新）。
    // Riverpod は「前と別のものか」で変化を見分けるので、同じ Set の中身を変えても気づかない
    // （React で配列を push しても再レンダーされず、setState([...prev, x]) と書くのと同じ）。
    // {...state, jobId}: 今の Set の中身を全部広げ、jobId を足した新しい Set（スプレッド構文）。
    state = {...state, jobId};
  }
}
