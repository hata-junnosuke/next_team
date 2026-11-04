// アプリ全体の外枠。テーマと画面遷移（go_router）をつなぎ、アプリ全体で共有する状態を持つ。
// Next.js で言うと app/layout.tsx（全ページに共通の外側）に当たる。
//
// MaterialApp.router: https://api.flutter.dev/flutter/material/MaterialApp/MaterialApp.router.html
// State のライフサイクル（dispose）: https://api.flutter.dev/flutter/widgets/State-class.html

import 'package:flutter/material.dart';

import 'core/router.dart';
import 'core/theme.dart';
import 'features/jobs/presentation/applied_jobs_scope.dart';

// 「どの募集に応募したか」を一覧と詳細で共有するため、状態をここ（いちばん上）まで持ち上げた。
// 状態を持つので Stateful にする（理由は applied_jobs_scope.dart の先頭）。
class WorkerApp extends StatefulWidget {
  // {super.key}: 名前付き引数 key を親クラスにそのまま渡す書き方。
  // key は React の key と同じく、Widget を見分けるための印。
  const WorkerApp({super.key});

  @override
  State<WorkerApp> createState() => _WorkerAppState();
}

class _WorkerAppState extends State<WorkerApp> {
  // アプリが動いている間、ずっと同じものを使う。
  final AppliedJobs _appliedJobs = AppliedJobs();

  // dispose: この State が捨てられるときに 1 回呼ばれる（React の useEffect の後片付けの return）。
  // ChangeNotifier は購読者の一覧を持っているので、使い終わったら dispose で片付ける。
  @override
  void dispose() {
    _appliedJobs.dispose();
    super.dispose();
  }

  // @override: 親クラスのメソッドを上書きしている印。build は React の return (JSX) に当たり、画面の形を返す。
  @override
  Widget build(BuildContext context) {
    // MaterialApp.router ごと AppliedJobsScope で包むので、ルーターが作るすべての画面から読める。
    return AppliedJobsScope(
      appliedJobs: _appliedJobs,
      // MaterialApp.router: URL（パス）で画面を決めるルーター版の MaterialApp。
      // 画面の切り替えはすべて router（core/router.dart）に任せる。
      child: MaterialApp.router(
        title: 'ワーカーアプリ',
        theme: appTheme,
        routerConfig: router,
      ),
    );
  }
}
