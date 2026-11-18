// 拠点の絞り込みの Provider のテスト（Step 9。Step 4 の Provider と ref の復習）。
// activeLocationIdProvider は、選んだ拠点（selectedLocationProvider）と、選択肢（locationOptionsProvider）の
// 2 つを ref.watch して組み合わせる Provider。React で言うと、2 つの state から useMemo で値を出すのに近い。
// 選んだ拠点が選択肢から消えたとき（管理画面で拠点を消した など）は、絞り込まない（null）に戻す。

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/ui/jobs/view_models/location_filter_provider.dart';

import '../../../fakes/fake_job_repository.dart';
import '../../../helpers/provider_container.dart';

void main() {
  late FakeJobRepository repository;
  late ProviderContainer container;

  setUp(() {
    // 偽物の選択肢は 東京倉庫（10）と 大阪倉庫（20）。
    repository = FakeJobRepository();
    container = containerWith(repository);
  });

  // 選択肢を読み終えるまで待ってから、activeLocationId を読む。
  // 失敗しても先へ進めるように、例外は受け止めて捨てる（catchError）。
  Future<int?> activeAfterLoad() async {
    container.listen(activeLocationIdProvider, (_, _) {});
    await container
        .read(locationOptionsProvider.future)
        .then<void>((_) {}, onError: (Object _) {});
    return container.read(activeLocationIdProvider);
  }

  test('選んだ拠点が選択肢にあれば activeLocationId はその id', () async {
    // .notifier: Notifier（状態を変えるメソッドを持つ側）を取り出す。React の useState の setter に当たる。
    container.read(selectedLocationProvider.notifier).select(20);

    expect(await activeAfterLoad(), 20);
  });

  test('選択肢にない拠点なら null', () async {
    container.read(selectedLocationProvider.notifier).select(99);

    expect(await activeAfterLoad(), isNull);
  });

  test('選択肢の読み込みに失敗したら null', () async {
    repository.locationsError = const FormatException('形が違います');
    container.read(selectedLocationProvider.notifier).select(10);

    // 選択肢が分からないときは絞り込まない（古い拠点 id を API に送って 422 にしない）。
    expect(await activeAfterLoad(), isNull);
  });
}
