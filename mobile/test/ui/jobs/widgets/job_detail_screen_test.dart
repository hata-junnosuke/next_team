// 募集の詳細画面（JobDetailScreen）の Widget テスト（Step 9。docs/05 の Step 9 の「残り 0 枠でボタンが押せない」。
// Step 7 の can.apply・my_application と、応募の mutation の表示の復習）。
// 応募のボタンの文言と押せるかどうかは、API の値（can.apply・my_application・remaining）だけで決まる。
// アプリで「定員に達したか」を計算していないことを、画面の側からも確かめる（mobile/CLAUDE.md のルール 1）。

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/config/dependencies.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/domain/models/job_posting.dart';
import 'package:worker_app/ui/jobs/widgets/job_detail_screen.dart';

import '../../../fakes/fake_job_repository.dart';
import '../../../helpers/json_fixtures.dart';

void main() {
  late FakeJobRepository repository;

  setUp(() {
    repository = FakeJobRepository();
  });

  // 詳細画面だけを描く。jobRepositoryProvider を偽物にするだけで、画面と Provider は本物のまま動く。
  Future<void> pumpDetail(WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [jobRepositoryProvider.overrideWithValue(repository)],
        retry: (_, _) => null,
        child: const MaterialApp(home: JobDetailScreen(jobId: 1)),
      ),
    );
    // 1 回目の pump で読み込み中、2 回目で偽物の応答が届いた後の画面になる。
    await tester.pump();
  }

  // 下の応募のボタン（FilledButton）。ダイアログが開いていないときは 1 つだけ。
  FilledButton applyButton(WidgetTester tester) =>
      tester.widget<FilledButton>(find.byType(FilledButton));

  testWidgets('残り 0 枠で応募できない募集では、ボタンが『満員』で押せない', (tester) async {
    repository.jobPosting = JobPosting.fromJson(
      jobPostingJson(remaining: 0, canApply: false),
    );

    await pumpDetail(tester);

    expect(find.widgetWithText(FilledButton, '満員'), findsOneWidget);
    // onPressed が null = 押せない（灰色のボタン）。
    expect(applyButton(tester).onPressed, isNull);
    expect(find.text('0枠（定員 3人）'), findsOneWidget);
  });

  testWidgets('応募済みなら my_application の label を出して押せない', (tester) async {
    repository.jobPosting = JobPosting.fromJson(
      jobPostingJson(
        myApplication: myApplicationJson(statusLabel: '応募中'),
        canApply: false,
      ),
    );

    await pumpDetail(tester);

    expect(find.widgetWithText(FilledButton, '応募中'), findsOneWidget);
    expect(applyButton(tester).onPressed, isNull);
  });

  testWidgets('応募するで確認ダイアログが出て、やめるなら送らない', (tester) async {
    await pumpDetail(tester);

    await tester.tap(find.widgetWithText(FilledButton, '応募する'));
    // ダイアログが開くアニメーションを最後まで進める。
    // pumpAndSettle: 動きが止まるまで pump を繰り返す（くるくるが回っている間は止まらないので、使う場所を選ぶ）。
    await tester.pumpAndSettle();
    expect(find.text('この募集に応募しますか？'), findsOneWidget);
    // ダイアログに日時・時給・交通費が出る（Step 7 の確認ダイアログ）。
    expect(find.text('時給: 1,200円'), findsOneWidget);

    await tester.tap(find.text('やめる'));
    await tester.pumpAndSettle();

    expect(find.text('この募集に応募しますか？'), findsNothing);
    expect(repository.applyCalls, isEmpty);
  });

  testWidgets('確認して応募するとスナックバー『応募しました』が出る', (tester) async {
    await pumpDetail(tester);

    await tester.tap(find.widgetWithText(FilledButton, '応募する'));
    await tester.pumpAndSettle();
    // ダイアログの中の「応募する」を押す（下のボタンと同じ文言なので、AlertDialog の中に絞って探す）。
    await tester.tap(
      find.descendant(
        of: find.byType(AlertDialog),
        matching: find.widgetWithText(FilledButton, '応募する'),
      ),
    );
    await tester.pumpAndSettle();

    expect(repository.applyCalls, [1]);
    expect(find.text('応募しました'), findsOneWidget);
  });

  testWidgets('422 ならサーバの message をボタンの上に出す', (tester) async {
    repository.applyError = const ApiError(
      statusCode: 422,
      message: '定員に達しているため応募できません。',
    );
    await pumpDetail(tester);

    await tester.tap(find.widgetWithText(FilledButton, '応募する'));
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(AlertDialog),
        matching: find.widgetWithText(FilledButton, '応募する'),
      ),
    );
    await tester.pumpAndSettle();

    // アプリは文言を作らず、API の message をそのまま出す（docs/01 の 7A.4）。
    expect(find.text('定員に達しているため応募できません。'), findsOneWidget);
    expect(find.text('応募しました'), findsNothing);
  });

  testWidgets('404 なら『募集が見つかりません』', (tester) async {
    // リポジトリは 404 を null にして返す（api_job_repository_test.dart の「詳細が 404 なら null」）。
    repository.jobPosting = null;

    await pumpDetail(tester);

    expect(find.text('募集が見つかりません'), findsOneWidget);
    expect(find.byType(FilledButton), findsNothing);
  });

  // 送信中と成功の後はボタンを押せない（2 回押しても 1 回しか送らない。docs/01 の 7A.1）。
  // 送信の二重の防ぎは apply_mutation_test.dart で見ているので、ここでは画面のボタンが押せないことだけを見る。
  testWidgets('送信中は応募のボタンが押せない', (tester) async {
    repository.applyGate = Completer<void>();
    await pumpDetail(tester);

    await tester.tap(find.widgetWithText(FilledButton, '応募する'));
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byType(AlertDialog),
        matching: find.widgetWithText(FilledButton, '応募する'),
      ),
    );
    // ダイアログが閉じるまで進める（くるくるが回るので pumpAndSettle は使えない。時間を指定して進める）。
    await tester.pump();
    await tester.pump(const Duration(seconds: 1));
    expect(find.byType(AlertDialog), findsNothing);

    expect(applyButton(tester).onPressed, isNull);

    repository.applyGate!.complete();
    await tester.pumpAndSettle();
  });
}
