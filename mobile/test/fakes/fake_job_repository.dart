// テスト用の偽のリポジトリ（Step 9。issue #50 の Q1: パッケージを足さず手書きにする）。
// Provider のテストで、jobRepositoryProvider をこれに差し替える（ProviderContainer.test の overrides）。
// Step 3〜6 で lib/ にあった FakeJobRepository（偽データで画面を作るためのもの）とは役目が違う。
// こちらは「何回・どの引数で呼ばれたか」を記録し、テストが用意した値か例外を返すだけ。
// Rails で言うと、サービスのテストで外部の窓口を差し替えるテスト用のクラス。React なら jest.fn() を並べたもの。
//
// implements JobRepository: 抽象クラス（interface）の約束どおりに全部のメソッドを書く。
// 1 つでも足りなければコンパイルが通らないので、JobRepository にメソッドが増えたらここも直すことになる。

import 'dart:async';

import 'package:worker_app/data/repositories/job_repository.dart';
import 'package:worker_app/domain/models/job_posting.dart';
import 'package:worker_app/domain/models/job_posting_page.dart';
import 'package:worker_app/domain/models/schedule_tab.dart';
import 'package:worker_app/domain/models/worker_application.dart';

import '../helpers/json_fixtures.dart';

// fetchJobPostings に渡された引数。レコード型（名前付きの組。TypeScript の { from, to, locationId } に近い）。
// https://dart.dev/language/records
typedef JobPostingsQuery = ({DateTime? from, DateTime? to, int? locationId});

class FakeJobRepository implements JobRepository {
  // --- 返す値（テストで差し替える）。既定は「よくある 1 件」 ---
  JobPostingPage jobPostingPage = JobPostingPage.fromJson(
    jobPostingPageJson([jobPostingJson()]),
  );
  List<JobLocation> locations = const [
    JobLocation(id: 10, name: '東京倉庫'),
    JobLocation(id: 20, name: '大阪倉庫'),
  ];
  JobPosting? jobPosting = JobPosting.fromJson(jobPostingJson());
  WorkerApplication appliedApplication = WorkerApplication.fromJson(
    workerApplicationJson(),
  );
  List<WorkerApplication> myApplications = const [];
  WorkerApplication withdrawnApplication = WorkerApplication.fromJson(
    workerApplicationJson(
      statusValue: 'withdrawn',
      statusLabel: '取り下げ',
      canWithdraw: false,
    ),
  );

  // --- 投げる例外（null なら投げない）。メソッドごとに分ける ---
  Object? jobPostingsError;
  Object? locationsError;
  Object? jobPostingError;
  Object? applyError;
  Object? withdrawError;

  // --- 応答を止める「門」。Completer は「あとで自分で完了させる Future」（JavaScript で resolve を外に持ち出した Promise）。
  // 門があると、応募・取り下げは complete() されるまで返らない。「送信中にもう一度押す」場面を作るのに使う。
  // https://api.dart.dev/dart-async/Completer-class.html
  Completer<void>? applyGate;
  Completer<void>? withdrawGate;

  // --- 呼ばれた記録 ---
  final List<JobPostingsQuery> jobPostingsCalls = [];
  int locationsCalls = 0;
  final List<int> jobPostingCalls = [];
  final List<int> applyCalls = [];
  final List<ScheduleTab> myApplicationsCalls = [];
  final List<int> withdrawCalls = [];

  @override
  Future<JobPostingPage> fetchJobPostings({
    DateTime? from,
    DateTime? to,
    int? locationId,
  }) async {
    jobPostingsCalls.add((from: from, to: to, locationId: locationId));
    _throwIfSet(jobPostingsError);
    return jobPostingPage;
  }

  @override
  Future<List<JobLocation>> fetchLocations() async {
    locationsCalls++;
    _throwIfSet(locationsError);
    return locations;
  }

  @override
  Future<JobPosting?> findJobPosting(int id) async {
    jobPostingCalls.add(id);
    _throwIfSet(jobPostingError);
    return jobPosting;
  }

  @override
  Future<WorkerApplication> apply(int jobPostingId) async {
    applyCalls.add(jobPostingId);
    // ?.: 門がなければ（null なら）待たない。
    await applyGate?.future;
    _throwIfSet(applyError);
    return appliedApplication;
  }

  @override
  Future<List<WorkerApplication>> fetchMyApplications(ScheduleTab tab) async {
    myApplicationsCalls.add(tab);
    return myApplications;
  }

  @override
  Future<WorkerApplication> withdraw(int applicationId) async {
    withdrawCalls.add(applicationId);
    await withdrawGate?.future;
    _throwIfSet(withdrawError);
    return withdrawnApplication;
  }

  // Object は何でも投げられる（Dart は Exception でなくても throw できる）。テストでは ApiError や FormatException を入れる。
  void _throwIfSet(Object? error) {
    if (error != null) throw error;
  }
}
