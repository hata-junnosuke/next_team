// WorkerApplication（自分の応募）の fromJson のテスト（Step 9。Step 7 の freezed と、Step 8 の can.withdraw の復習）。
// 取り下げのボタンは can.withdraw だけで出し分ける（mobile/CLAUDE.md のルール 1）ので、
// can.withdraw を読めること・欠けたら黙って false にせず例外になることを守る。

import 'package:flutter_test/flutter_test.dart';
import 'package:json_annotation/json_annotation.dart';
import 'package:worker_app/domain/models/worker_application.dart';

import '../../helpers/json_fixtures.dart';

void main() {
  test('snake_case の JSON を WorkerApplication に変換し、can.withdraw を読める', () {
    final application = WorkerApplication.fromJson(
      workerApplicationJson(id: 9, jobTitle: '梱包', canWithdraw: true),
    );

    expect(application.id, 9);
    expect(application.jobPosting.jobTitle, '梱包');
    expect(application.jobPosting.location.name, '東京倉庫');
    expect(application.status.label, '応募中');
    expect(application.confirmedAt, isNull);
    expect(application.cancelReason, isNull);
    expect(application.can.withdraw, isTrue);

    final confirmed = WorkerApplication.fromJson(
      workerApplicationJson(
        statusValue: 'confirmed',
        statusLabel: '確定',
        canWithdraw: false,
      ),
    );
    expect(confirmed.can.withdraw, isFalse);
  });

  test('can が欠けた古い形の応答は CheckedFromJsonException になる', () {
    // Step 8 より前の API（can がない）の応答を受けたら、契約のずれとして止まる（docs/01 の 7A.4）。
    // ここで false にして黙って進むと、取り下げのボタンが静かに消えるだけで、ずれに気づけない。
    final json = workerApplicationJson()..remove('can');

    expect(
      () => WorkerApplication.fromJson(json),
      throwsA(
        isA<CheckedFromJsonException>().having((e) => e.key, 'key', 'can'),
      ),
    );
  });
}
