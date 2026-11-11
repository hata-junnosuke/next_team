// ログイン中のワーカー自身の情報（GET /api/worker/me）を持つ Provider。マイページが読む。
// React で言うと useQuery({ queryKey: ['me'], queryFn: fetchMe })。
//
// 引っ張って更新: https://riverpod.dev/ja/docs/how_to/pull_to_refresh

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../config/dependencies.dart';
import '../../../domain/models/worker_me.dart';

part 'me_provider.g.dart';

// 何を持つか: 自分の名前・メール・企業（WorkerMe）。
// いつ作られるか: マイページが初めて ref.watch したとき。
// いつ作り直されるか: ref.invalidate(meProvider) されたとき（引っ張って更新・再読み込み）。
// keepAlive なしなので、ログアウトしてマイページがなくなると捨てられ、次のログインで取り直す
// （前のワーカーの情報が残らない）。
// retry: 自動のやり直しを切る。401（無効にされた）や 404 はやり直しても同じなので、人が「再読み込み」で決める
// （jobListProvider と同じ考え方）。
@Riverpod(retry: _noRetry)
Future<WorkerMe> me(Ref ref) => ref.watch(authRepositoryProvider).fetchMe();

Duration? _noRetry(int retryCount, Object error) => null;
