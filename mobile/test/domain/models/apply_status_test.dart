// ApplyStatus.of（応募のボタンの出し分け）のテスト（Step 9。Step 3 の sealed と switch、Step 7 の can.apply と my_application の復習）。
// 出し分けは API の my_application・can.apply・remaining だけで決める（mobile/CLAUDE.md のルール 1）。
// アプリで「応募できるか」を計算していないこと（定員・状態の値を見ていないこと）を、優先順位ごとに確かめる。
//
// switch の上から順に当てはめるので、「どれが優先か」がそのまま表示の決まりになる。
// React で言うと、if 〜 else if の並びで出すボタンを決めているコンポーネントのテスト。

import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/domain/models/apply_status.dart';
import 'package:worker_app/domain/models/job_posting.dart';

import '../../helpers/json_fixtures.dart';

// テストの中で何度も書くので、JSON から JobPosting を作って ApplyStatus を求める所をまとめる。
ApplyStatus statusOf(Map<String, dynamic> json) =>
    ApplyStatus.of(JobPosting.fromJson(json));

void main() {
  test('my_application があればその label の ApplyApplied', () {
    final status = statusOf(
      jobPostingJson(
        myApplication: myApplicationJson(
          statusValue: 'confirmed',
          statusLabel: '確定',
        ),
        canApply: false,
      ),
    );

    // isA<型>().having(...): 型を確かめ、さらにその中の値を確かめる（PHPUnit なら assertInstanceOf + assertSame）。
    expect(status, isA<ApplyApplied>().having((s) => s.label, 'label', '確定'));
  });

  test('取り下げた応募（my_application の status が withdrawn）も ApplyApplied で、label は『取り下げ』', () {
    // 取り下げた募集にはもう応募できない（Step 8）。アプリは status.value を見ず、API の label をそのまま出す。
    final status = statusOf(
      jobPostingJson(
        myApplication: myApplicationJson(
          statusValue: 'withdrawn',
          statusLabel: '取り下げ',
        ),
        canApply: false,
      ),
    );

    expect(status, isA<ApplyApplied>().having((s) => s.label, 'label', '取り下げ'));
  });

  test('my_application は can.apply より優先', () {
    // 本来 API は両方を同時に返さないが、もし返ってきても「応募済み」を出す（二重に押させない）。
    final status = statusOf(
      jobPostingJson(myApplication: myApplicationJson(), canApply: true),
    );

    expect(status, isA<ApplyApplied>());
  });

  test('can.apply が true なら ApplyAvailable', () {
    expect(statusOf(jobPostingJson(canApply: true)), isA<ApplyAvailable>());
  });

  test('どちらでもなく remaining が 0 なら ApplyFull', () {
    expect(
      statusOf(jobPostingJson(canApply: false, remaining: 0)),
      isA<ApplyFull>(),
    );
  });

  test('どちらでもなく枠が残っていれば ApplyUnavailable', () {
    // 枠はあるのに can.apply が false（締め切った・勤務日が過ぎた など）。理由はアプリでは判定しない。
    expect(
      statusOf(jobPostingJson(canApply: false, remaining: 2)),
      isA<ApplyUnavailable>(),
    );
  });
}
