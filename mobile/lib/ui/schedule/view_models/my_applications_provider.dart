// 「よてい」の一覧（自分の応募）を持つ Provider（Step 8）。よていの画面はここから読むだけで、リポジトリを直接読まない。
// 作りは さがす の jobListProvider（job_list_provider.dart）と同じ「引数つきの Provider（family）」。
// React で言うと useQuery({ queryKey: ['myApplications', tab], queryFn: () => fetchMyApplications(tab) })。
//
// 引数つきの Provider（family）: https://riverpod.dev/ja/docs/concepts2/family
// ref.invalidate: https://riverpod.dev/ja/docs/concepts2/refs

import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../../../config/dependencies.dart';
import '../../../domain/models/schedule_tab.dart';
import '../../../domain/models/worker_application.dart';

part 'my_applications_provider.g.dart';

// そのタブ（確定 / 応募中）の自分の応募。
// 何を持つか: タブごとに、API から取ってきた自分の応募の一覧（キャッシュ）。
// いつ作られるか: よていの画面がそのタブを初めて ref.watch したとき。
// いつ作り直されるか:
// - 引っ張って更新・「再読み込み」で ref.invalidate(myApplicationsProvider(tab)) されたとき
// - 応募の後（apply_mutation.dart）と取り下げの後（withdraw_mutation.dart）に、
//   引数なしの ref.invalidate(myApplicationsProvider) で、どのタブの分も捨てられたとき
// - keepAlive を付けていないので、読む画面がなくなると捨てられ、次に読まれたときに取り直す
//
// retry: 自動でやり直さない（jobListProvider と同じ理由。API が止まっているときに、エラーと「再読み込み」がすぐ出るように）。
@Riverpod(retry: _noRetry)
Future<List<WorkerApplication>> myApplications(Ref ref, ScheduleTab tab) {
  return ref.watch(jobRepositoryProvider).fetchMyApplications(tab);
}

// 自動でやり直さない、という retry の関数（job_list_provider.dart と同じ）。
Duration? _noRetry(int retryCount, Object error) => null;
