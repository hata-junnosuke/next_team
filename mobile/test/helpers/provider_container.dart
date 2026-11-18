// Provider のテストで使う ProviderContainer の作り方（Step 9。Step 4 の Provider と ref の復習）。
// ProviderContainer は Provider の値を持つ入れ物。アプリでは ProviderScope（main.dart）が 1 つ作る。
// テストでは画面を作らずにこれを直に作り、container.read / listen で Provider を読む。
// React で言うと、<Provider>（Context）を画面なしで 1 つ用意して、hooks の中身だけを呼ぶのに近い。
// https://riverpod.dev/ja/docs/how_to/testing

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:worker_app/config/dependencies.dart';

import '../fakes/fake_job_repository.dart';

// jobRepositoryProvider だけを偽物に差し替えた入れ物を作る（issue #50 の Q1）。
// - overrides: 「この Provider はこの値を返す」と上書きする（Laravel のサービスコンテナの $this->app->instance()）。
//   アプリのコードは jobRepositoryProvider を読むだけなので、何も変えずに偽物を使わせられる。
// - retry: 失敗した Provider を自動で再試行しない（null を返す = 再試行しない）。
//   再試行のタイマーがテストの後に残ると落ちるので、テストでは切る（rules/common/testing.md）。
// - ProviderContainer.test: テストの終わりに自動で dispose する（addTearDown を自分で書かなくてよい）。
ProviderContainer containerWith(FakeJobRepository repository) {
  return ProviderContainer.test(
    overrides: [jobRepositoryProvider.overrideWithValue(repository)],
    retry: (_, _) => null,
  );
}
