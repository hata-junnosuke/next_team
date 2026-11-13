// 一覧の拠点の絞り込み（Step 7）。ドロップダウンの選択肢と、選んでいる拠点を持つ Provider。
// 選択肢は API（GET /api/worker/locations。docs/01 の 6.11）から取り、アプリに拠点の名前を持たない。
// 日付のチップ（date_filter_provider.dart）と同じ形で、選んだ値を Provider に置き、一覧の Provider の引数にする。
// React で言うと、<select> の options を useQuery で取り、選んだ値を useState（ここでは Notifier）に持つのに近い。
//
// Notifier: https://riverpod.dev/ja/docs/concepts2/providers

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../config/dependencies.dart';
import '../../../domain/models/job_posting.dart';

part 'location_filter_provider.g.dart';

// ドロップダウンの選択肢（自社の拠点すべて）。拠点の形 {id, name} は募集の location と同じなので JobLocation を使う。
// 何を持つか: API から取った拠点の一覧。
// いつ作り直されるか: keepAlive なし。一覧の画面が watch し続ける間は持ち、閉じると捨てる。
// 失敗しても一覧は「すべての拠点」で出す（画面は選択肢がないときドロップダウンに「すべての拠点」だけを出す）。
// retry は既定のまま（拠点の一覧は小さく、失敗しても一覧の表示を止めないので、裏で何度かやり直してよい）。
@riverpod
Future<List<JobLocation>> locationOptions(Ref ref) =>
    ref.watch(jobRepositoryProvider).fetchLocations();

// 選んでいる拠点の id（null なら「すべての拠点」）。
// 何を持つか: int?（int か null）。最初は null。
// いつ作り直されるか: keepAlive なし。日付のチップと同じく、さがすタブが残っている間は残る。
@riverpod
class SelectedLocation extends _$SelectedLocation {
  @override
  int? build() => null;

  void select(int? locationId) {
    state = locationId;
  }
}
