// 募集の一覧の 1 ページ（API の一覧の応答 {"data": [...], "meta": {...}}。docs/01 の 6.1）。
// 一覧は per_page=100 の 1 ページ目だけを読む（issue #48 の Q3）。meta.total（条件に合う全件数）が
// 読んだ件数より多いときに、画面が「ほかに N 件あります」と出すために meta も型にする。
// TypeScript で言うと、z.object({ data: z.array(jobPosting), meta: pageMeta }) のスキーマ。
//
// freezed の書き方は job_posting.dart を参照。

import 'package:freezed_annotation/freezed_annotation.dart';

import 'job_posting.dart';

part 'job_posting_page.freezed.dart';
part 'job_posting_page.g.dart';

@freezed
abstract class JobPostingPage with _$JobPostingPage {
  // 応答の links（前後のページの URL）は使わないので持たない。JSON に余分なキーがあっても、生成した fromJson は無視する。
  const JobPostingPage._();

  const factory JobPostingPage({
    // 生成した fromJson は、配列の 1 件ずつに JobPosting.fromJson を呼ぶ。
    required List<JobPosting> data,
    required PageMeta meta,
  }) = _JobPostingPage;

  factory JobPostingPage.fromJson(Map<String, dynamic> json) =>
      _$JobPostingPageFromJson(json);

  // 読みきれなかった件数（全件数 − 読んだ件数）。0 なら全部読めている。
  // get: 引数なしで呼べるメソッド（getter。TypeScript の get hidden() {}）。
  // freezed のクラスにメソッドを足すので、上に private コンストラクタ（JobPostingPage._()）を書いている（api_error.dart と同じ）。
  int get hiddenCount => meta.total - data.length;
}

// ページの情報（6.1 の meta）。今使うのは total だけだが、6.1 の 4 つを型にしておく。
// 応答の meta には from・to・links なども入っているが、使わないので持たない。
@freezed
abstract class PageMeta with _$PageMeta {
  const factory PageMeta({
    required int currentPage,
    required int lastPage,
    required int perPage,
    required int total,
  }) = _PageMeta;

  factory PageMeta.fromJson(Map<String, dynamic> json) =>
      _$PageMetaFromJson(json);
}
