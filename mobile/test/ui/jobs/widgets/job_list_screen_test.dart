// 募集の一覧画面（JobListScreen）の Widget テスト（Step 9。Step 7 の一覧のページと、再読み込みの復習）。
// - 一覧は per_page=100 の 1 ページ目だけ。total が多ければ「ほかに N 件あります」を出す（docs/01 の 7A.1）
// - 読み込みに失敗したら、再読み込みのボタンで取り直す（ref.invalidate）

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/config/dependencies.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/domain/models/job_posting_page.dart';
import 'package:worker_app/ui/jobs/widgets/job_list_screen.dart';

import '../../../fakes/fake_job_repository.dart';
import '../../../helpers/json_fixtures.dart';

void main() {
  late FakeJobRepository repository;

  setUp(() {
    repository = FakeJobRepository();
  });

  Future<void> pumpList(WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [jobRepositoryProvider.overrideWithValue(repository)],
        retry: (_, _) => null,
        child: const MaterialApp(home: JobListScreen()),
      ),
    );
    await tester.pump();
  }

  testWidgets('total が件数より多いと『ほかに N 件あります』を出す', (tester) async {
    repository.jobPostingPage = JobPostingPage.fromJson(
      jobPostingPageJson([
        jobPostingJson(id: 1, jobTitle: 'ピッキング'),
        jobPostingJson(id: 2, jobTitle: '検品'),
      ], total: 5),
    );

    await pumpList(tester);

    expect(find.text('ピッキング'), findsOneWidget);
    expect(find.text('検品'), findsOneWidget);
    // 5 件のうち 2 件を出したので、残りは 3 件。数えるのは JobPostingPage.hiddenCount（job_posting_test.dart）。
    expect(find.text('ほかに 3 件あります。日付や拠点で絞り込んでください。'), findsOneWidget);
  });

  testWidgets('エラーなら再読み込みのボタンが出て、押すと取り直す', (tester) async {
    repository.jobPostingsError = const ApiError(
      statusCode: 500,
      message: 'サーバーでエラーが起きました。',
    );
    await pumpList(tester);

    expect(find.text('募集を読み込めませんでした'), findsOneWidget);
    // 画面は ApiError の型を見ず、'$error'（toString = message）を出す。
    expect(find.text('サーバーでエラーが起きました。'), findsOneWidget);
    expect(repository.jobPostingsCalls, hasLength(1));

    // 次は成功するようにしてから、再読み込みを押す。
    repository.jobPostingsError = null;
    await tester.tap(find.widgetWithText(FilledButton, '再読み込み'));
    await tester.pump();
    await tester.pump();

    expect(repository.jobPostingsCalls, hasLength(2));
    expect(find.text('募集を読み込めませんでした'), findsNothing);
    expect(find.text('ピッキング'), findsOneWidget);
  });
}
