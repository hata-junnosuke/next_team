// アプリの入口。起動して WorkerApp を画面に出すだけ。
// Next.js で言うと、フレームワークが持っている「起動処理」に当たる（自分では書かない部分）。
// 中身は app.dart に置き、ここには次の 3 つ以外は足さない:
// 接続先の空の検査、保存したログインの状態を戻すこと（Step 6）、ProviderScope で包むこと。
//
// https://api.flutter.dev/flutter/widgets/runApp.html
// ProviderScope と ProviderContainer: https://riverpod.dev/ja/docs/concepts2/containers

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'config/dependencies.dart';
import 'data/services/api_client.dart';

// void main() はプログラムの入口（Dart では必ず main から始まる）。
// Step 6 から async にした: 画面を出す前に、Keychain からトークンを読み終えるのを待つため。
// Future<void> は「終わりを待てる、値を返さない処理」（JavaScript の async function main(): Promise<void>）。
Future<void> main() async {
  // 接続先（--dart-define=API_BASE_URL）を渡し忘れたら、ここで止める（docs/01 の 7A.2）。
  // 止めずに起動すると、最初に API を呼んだ画面で「接続できません」と出て、原因が分かりにくい。
  // StateError は「この状態では続けられない」を表す Dart の標準の例外。
  // flutter run のターミナルに「Unhandled Exception: Bad state: …」と出る。
  if (apiBaseUrl.isEmpty) {
    throw StateError(
      'API_BASE_URL が空です。make mobile-run で起動するか、'
      'flutter run --dart-define=API_BASE_URL=http://localhost:8002 のように接続先を渡してください。',
    );
  }

  // runApp の前にプラグイン（flutter_secure_storage）を使うときは、先に Flutter の土台を用意する決まり。
  // https://api.flutter.dev/flutter/widgets/WidgetsFlutterBinding/ensureInitialized.html
  WidgetsFlutterBinding.ensureInitialized();

  // ProviderContainer = Provider の値をしまう入れ物そのもの（ProviderScope が中で作っているもの）。
  // ここで先に作り、画面を出す前に「保存したトークンがあるか」を読んでおく（T7）。
  // 待っている間は OS の起動画面（スプラッシュ）のままなので、ログイン画面が一瞬出ることがない。
  // React で言うと、最初の描画の前にセッションを読んでから <App> を描くのに近い。
  final container = ProviderContainer();
  await container.read(authRepositoryProvider).restore();

  runApp(
    // UncontrolledProviderScope: 外で作った container を、そのまま画面に渡す ProviderScope。
    // ProviderScope と同じく、アプリのいちばん外側に 1 つだけ置く（React の <Provider store={store}>）。
    // テストのときは ProviderContainer(overrides: [...]) で、偽のリポジトリなどに差し替えられる。
    UncontrolledProviderScope(container: container, child: const WorkerApp()),
  );
}
