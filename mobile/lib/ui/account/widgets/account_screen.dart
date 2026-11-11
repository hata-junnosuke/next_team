// マイページ タブの画面。パスは /account。
// 自分の名前・メールアドレス・企業名を出す（GET /api/worker/me。docs/01 の 6.11）。
// Next.js で言うと app/account/page.tsx に当たる。
//
// RefreshIndicator: https://api.flutter.dev/flutter/material/RefreshIndicator-class.html
// ListTile: https://api.flutter.dev/flutter/material/ListTile-class.html

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../domain/models/worker_me.dart';
import '../view_models/me_provider.dart';

// 自分では状態を持たず、meProvider を読んで描くだけなので ConsumerWidget にする（Step 5 までは Stateless の空の画面）。
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final meAsync = ref.watch(meProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('マイページ')),
      // 読み込み中 / エラー / データ の 3 つを必ず書く（一覧の画面と同じ when）。
      body: meAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => _ErrorView(
          // ApiError は文字列にすると日本語の文言になる（api_error.dart の toString）。
          message: '$error',
          isRetrying: meAsync.isLoading,
          onRetry: () => ref.invalidate(meProvider),
        ),
        // RefreshIndicator: 下に引っ張ると onRefresh を呼び、終わるまで上にくるくるを出す。
        data: (me) => RefreshIndicator(
          onRefresh: () => _refresh(ref),
          child: _MeView(me: me),
        ),
      ),
    );
  }

  // 引っ張って更新。meProvider を捨てて取り直し、届くまで待つ（一覧の画面の _refresh と同じ）。
  // 失敗したら meProvider が AsyncError になり、when の error が出すので、ここでは受け止めるだけ。
  Future<void> _refresh(WidgetRef ref) async {
    ref.invalidate(meProvider);
    try {
      await ref.read(meProvider.future);
    } on Exception catch (_) {
      // 画面の表示は when の error に任せる。
    }
  }
}

// 自分の情報の表示。値を受け取って描くだけなので Stateless。
class _MeView extends StatelessWidget {
  const _MeView({required this.me});

  final WorkerMe me;

  @override
  Widget build(BuildContext context) {
    // ListView にしておくと、中身が短くても RefreshIndicator で引っ張れる（AlwaysScrollable）。
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        // ListTile: 左にアイコン、右に見出しと説明を並べる 1 行（設定画面によくある行）。
        ListTile(
          leading: const Icon(Icons.person_outline),
          title: Text(me.name),
          subtitle: const Text('氏名'),
        ),
        ListTile(
          leading: const Icon(Icons.mail_outline),
          title: Text(me.email),
          subtitle: const Text('メールアドレス'),
        ),
        ListTile(
          leading: const Icon(Icons.business_outlined),
          title: Text(me.company.name),
          subtitle: Text('企業コード ${me.company.code}'),
        ),
      ],
    );
  }
}

// 読み込みに失敗したときの表示（一覧の画面の _ErrorView と同じ形）。
class _ErrorView extends StatelessWidget {
  const _ErrorView({
    required this.message,
    required this.isRetrying,
    required this.onRetry,
  });

  final String message;
  final bool isRetrying;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline, size: 48),
            const SizedBox(height: 12),
            const Text('自分の情報を読み込めませんでした'),
            const SizedBox(height: 4),
            Text(
              message,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: isRetrying ? null : onRetry,
              icon: isRetrying
                  ? const SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.refresh),
              label: const Text('再読み込み'),
            ),
          ],
        ),
      ),
    );
  }
}
