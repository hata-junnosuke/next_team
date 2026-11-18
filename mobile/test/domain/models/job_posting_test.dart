// JobPosting（募集）と JobPostingPage（一覧のページ）の fromJson のテスト（Step 9。Step 7 の freezed の復習）。
// Laravel で言うと、Resource の形を確かめる Unit テスト。ただし向きが逆で、
// こちらは「API が返す JSON を、アプリのモデルに正しく読めるか」を確かめる（web で言うと zod の parse のテスト）。
//
// flutter_test: Flutter に最初から入っているテストの道具（dev_dependencies。パッケージは足していない）。
// test('名前', () { ... }) が 1 本のテスト（PHPUnit の test メソッド、Jest の test()）。
// group('名前', () { ... }) は名前をまとめる箱（Jest の describe）。
// expect(実際の値, 期待の形) で確かめる（PHPUnit の assertSame、Jest の expect().toBe()）。
// https://docs.flutter.dev/cookbook/testing/unit/introduction

import 'package:flutter_test/flutter_test.dart';
import 'package:json_annotation/json_annotation.dart';
import 'package:worker_app/domain/models/job_posting.dart';
import 'package:worker_app/domain/models/job_posting_page.dart';

import '../../helpers/json_fixtures.dart';

void main() {
  group('JobPosting.fromJson', () {
    test('snake_case の JSON を JobPosting に変換できる', () {
      final job = JobPosting.fromJson(
        jobPostingJson(
          id: 7,
          jobTitle: '検品',
          workDate: '2026-09-21',
          hourlyWage: 1300,
          remaining: 1,
          myApplication: myApplicationJson(id: 55),
        ),
      );

      // build.yaml の field_rename: snake で、job_title → jobTitle のように読めている。
      expect(job.id, 7);
      expect(job.jobTitle, '検品');
      expect(job.location.name, '東京倉庫');
      // work_date の "2026-09-21" は DateTime になる（生成した fromJson が DateTime.parse を呼ぶ）。
      expect(job.workDate, DateTime(2026, 9, 21));
      expect(job.startTime, '09:00');
      expect(job.hourlyWage, 1300);
      expect(job.remaining, 1);
      expect(job.status.label, '募集中');
      expect(job.can.apply, isTrue);
      // ?.: myApplication が null でなければ id を読む（null なら null）。
      expect(job.myApplication?.id, 55);
      expect(job.myApplication?.status.label, '応募中');
    });

    test('my_application が null なら myApplication も null', () {
      final job = JobPosting.fromJson(jobPostingJson());

      expect(job.myApplication, isNull);
    });

    test('必須のキーが欠けると CheckedFromJsonException になる', () {
      // ..remove('job_title'): カスケード記法。Map を作ってから remove を呼び、Map 自身を返す
      // （JavaScript なら const m = {...}; delete m.job_title; を 1 式で書く感じ）。
      // https://dart.dev/language/operators#cascade-notation
      final json = jobPostingJson()..remove('job_title');

      // throwsA(isA<型>()): その型の例外を投げること（PHPUnit の expectException）。
      // 関数を呼んだ結果ではなく「呼ぶ関数」を渡すので () => と書く。
      expect(
        () => JobPosting.fromJson(json),
        throwsA(
          isA<CheckedFromJsonException>().having(
            (e) => e.key,
            'どのキーで失敗したか',
            'job_title',
          ),
        ),
      );
    });
  });

  group('JobPostingPage', () {
    test('hiddenCount は total から出した件数を引いた数', () {
      final page = JobPostingPage.fromJson(
        jobPostingPageJson([
          jobPostingJson(id: 1),
          jobPostingJson(id: 2),
        ], total: 5),
      );

      expect(page.data, hasLength(2));
      expect(page.meta.total, 5);
      // 一覧の下の「ほかに 3 件あります」の 3（docs/01 の 7A.1 の募集の一覧のページ）。
      expect(page.hiddenCount, 3);
    });
  });
}
