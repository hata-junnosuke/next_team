// data/services/api_client.dart のテスト（Step 9）。
// - parseModel: 生成した fromJson の失敗を FormatException にそろえる関数（Step 7 の復習）
//
// parseModel は「どのリポジトリも通る 1 か所」なので、ここを守れば画面の on FormatException が効き続ける。
// web で言うと、zod の parse が落ちたときに決まった例外になることを確かめるテスト。

import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/data/services/api_client.dart';
import 'package:worker_app/domain/models/job_posting.dart';

import '../../helpers/json_fixtures.dart';

void main() {
  group('parseModel', () {
    test('Map でなければ FormatException', () {
      // 配列（List）や文字列が来たら、fromJson に渡す前に止める。
      expect(
        () => parseModel(<Object?>[1, 2], JobPosting.fromJson),
        throwsA(isA<FormatException>()),
      );
      expect(
        () => parseModel('oops', JobPosting.fromJson),
        throwsA(isA<FormatException>()),
      );
    });

    test('形が違えば FormatException にそろえ、メッセージにキー名が入る', () {
      // remaining が数でなく文字列。生成した fromJson は CheckedFromJsonException を投げ、
      // parseModel がそれを FormatException に変える（クラス名とキーだけで、値は入れない）。
      final json = jobPostingJson()..['remaining'] = 'たくさん';

      expect(
        () => parseModel(json, JobPosting.fromJson),
        throwsA(
          isA<FormatException>()
              .having((e) => e.message, 'message', contains('remaining'))
              .having((e) => e.message, 'message', contains('JobPosting'))
              // 値（たくさん）は例外の文言に入れない（個人情報を例外に残さない方針）。
              .having((e) => e.message, 'message', isNot(contains('たくさん'))),
        ),
      );
    });

    test('形が合えばモデルを返す', () {
      final job = parseModel(jobPostingJson(id: 3), JobPosting.fromJson);

      expect(job.id, 3);
    });
  });
}
