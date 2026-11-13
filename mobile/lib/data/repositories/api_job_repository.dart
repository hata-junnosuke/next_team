// 本物の API から募集を取るリポジトリ（Step 7）。Step 3〜6 の偽物（同梱した JSON を読む）と入れ替えた。
// 差し替えは config/dependencies.dart の jobRepositoryProvider の 1 行だけで、画面と view_models は変えない
// （JobRepository という約束（型）に対して書いてあるため。docs/01 の 7A.1）。
// Next.js で言うと、web/ の lib/api.ts の上に「募集を取る関数」を並べたファイル（lib/jobs.ts のようなもの）。
// Rails で言うと、外部 API を呼ぶクライアントのクラス（Faraday を包んだ JobPostingsClient）。
//
// API を呼ぶのは ApiClient だけ（dio を直接使わない。7A.4）。JSON は parseModel() でモデルに変えてから返す。
// data/ なので Riverpod は import しない（7A.1）。
//
// 使う API（docs/01 の 6.11）:
// - GET /api/worker/job-postings?date_from=&date_to=&per_page=100  一覧（ページあり）
// - GET /api/worker/job-postings/{id}                                1 件（ほかは 404）
// - POST /api/worker/job-postings/{id}/applications                 応募（本文は空。201 で自分の応募）

import '../../domain/models/job_posting.dart';
import '../../domain/models/worker_application.dart';
import '../services/api_client.dart';
import '../services/api_error.dart';
import 'job_repository.dart';

class ApiJobRepository implements JobRepository {
  // required this._apiClient: 受け取った ApiClient をそのまま _apiClient に入れる（auth_repository.dart と同じ書き方）。
  ApiJobRepository({required this._apiClient});

  final ApiClient _apiClient;

  // 一覧で読む件数。6.1 の per_page の最大の 100 にし、1 ページ目だけを読む（issue #48 の Q3）。
  // 無限スクロールにしない代わりに、入りきらないときは絞り込みを促す（T10）。
  static const int _perPage = 100;

  @override
  Future<List<JobPosting>> fetchJobPostings({
    DateTime? from,
    DateTime? to,
  }) async {
    final body = await _apiClient.get(
      '/api/worker/job-postings',
      // Map の中の if: 条件が true のときだけ、その組を入れる（collection if。JSX の {cond && <X />} に近い）。
      // 「すべて」（from・to が null）のときは date_from・date_to を送らない（送ると API が絞り込む）。
      query: {
        'per_page': _perPage,
        if (from != null) 'date_from': _ymd(from),
        if (to != null) 'date_to': _ymd(to),
      },
    );
    // 一覧の応答は {"data": [...], "meta": {...}, "links": {...}}（6.1）。data の配列を 1 件ずつモデルにする。
    // 並び（勤務日 → 開始時刻 → id）は API が決めるので、アプリで並べ替えない。
    final data = switch (body) {
      {'data': final List<dynamic> data} => data,
      _ => throw const FormatException('募集の一覧の応答に data の配列がありません'),
    };
    // [for (...) 式]: 配列を作りながら 1 件ずつ変換する（collection for。JavaScript の data.map(...)）。
    return [for (final item in data) parseModel(item, JobPosting.fromJson)];
  }

  @override
  Future<JobPosting?> findJobPosting(int id) async {
    final Map<String, dynamic> body;
    try {
      body = await _apiClient.get('/api/worker/job-postings/$id');
    } on ApiError catch (e) {
      // 404 は「見つからない」（他社の募集・存在しない・中止や締切で見られない。6.11）。失敗ではなく null を返す。
      // 画面は null を見て「募集が見つかりません」を出す（偽物のときと同じ約束）。
      if (e.statusCode == 404) return null;
      // それ以外（通信の失敗・500 など）はそのまま投げ直す。rethrow は受けた例外をそのまま投げる（Ruby の raise）。
      rethrow;
    }
    // 1 件の応答は {"data": {...}}（6.1）。
    return switch (body) {
      {'data': final Object? data} => parseModel(data, JobPosting.fromJson),
      _ => throw const FormatException('募集の応答に data がありません'),
    };
  }

  @override
  Future<WorkerApplication> apply(int jobPostingId) async {
    // 本文は送らない（6.11 の入力は空）。誰が応募するかは、API がトークンのワーカーで決める。
    // 失敗（422 の「残り枠がないため応募できません。」など）は ApiClient が ApiError にして投げるので、
    // ここでは受けずに、そのまま呼んだ側（view_models の mutation）に渡す。
    final body = await _apiClient.post(
      '/api/worker/job-postings/$jobPostingId/applications',
    );
    // 201 の応答は {"data": WorkerApplicationResource}。本文がない（null）のも契約のずれ。
    return switch (body) {
      {'data': final Object? data} => parseModel(
        data,
        WorkerApplication.fromJson,
      ),
      _ => throw const FormatException('応募の応答に data がありません'),
    };
  }

  // DateTime を API の日付の形 "2026-09-20"（6.1）にする。
  // 表示用の utils/format.dart（2026/09/20（日））とは別の形なので、ここに置く。
  static String _ymd(DateTime date) {
    final month = date.month.toString().padLeft(2, '0');
    final day = date.day.toString().padLeft(2, '0');
    return '${date.year}-$month-$day';
  }
}
