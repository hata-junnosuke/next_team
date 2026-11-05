// 偽のリポジトリ。アプリに同梱した assets/jobs.json（API の一覧の応答と同じ形）を読んで募集を返す。
// リポジトリ = 「データをどこから取るか」を画面から隠す層。画面は「募集の一覧をちょうだい」と頼むだけで、
// 相手が偽物か本物の API かを知らない。Step 7 で ApiJobRepository（API を呼ぶ）に差し替える。
// Rails で言うと、コントローラが ActiveRecord を直接呼ばずにサービスを通すのに近い。
// Next.js で言うと web/ の lib/api.ts（画面は fetch を直接書かない）に当たる。
//
// なぜリポジトリ層を挟むか（公式の構成ガイド）: https://docs.flutter.dev/app-architecture/guide
// rootBundle（同梱したファイルを読む）: https://api.flutter.dev/flutter/services/rootBundle.html
// jsonDecode: https://dart.dev/libraries/dart-convert
// Future と async / await: https://dart.dev/libraries/async/async-await

// dart: で始まるのは Dart に最初から入っているライブラリ。jsonDecode はここにある。
import 'dart:convert';

// rootBundle を使うために読み込む。
import 'package:flutter/services.dart';

import '../domain/job_posting.dart';

class FakeJobRepository {
  const FakeJobRepository();

  // jobs.json の日付は、この日を「今日」として書いてある。
  // 固定の日付のままだと日がたつと全部過去の募集になるので、読み込むときに今日までずらす。
  // ずらすのは偽データだけの都合なので、fromJson ではなくこのリポジトリの中でやる。
  static final _baseDate = DateTime.utc(2026, 1, 5);

  // 募集の一覧。勤務日 → 開始時刻の順に並んでいる（API の並び順と同じ。docs/01 の 6.11）。
  // Future<T>: 「あとで T が届く」という約束の値（JavaScript の Promise<T>）。
  // async を付けた関数の中では await で Future の中身を待てる。戻り値は自動で Future に包まれる。
  // ファイルの読み込みも API の呼び出しも時間がかかるので、どちらも Future を返す形にそろえておく。
  Future<List<JobPosting>> fetchJobPostings() async {
    // 同梱したファイルを文字列で読む（JavaScript の await (await fetch(url)).text()）。
    final body = await rootBundle.loadString('assets/jobs.json');
    // 文字列を Map / List にする（JSON.parse）。返り値は dynamic なので、as で形を確かめる。
    final json = jsonDecode(body) as Map<String, dynamic>;
    // 一覧の応答は {"data": [...], "meta": {...}}（docs/01 の 6.1）。今は data だけを使う。
    final data = json['data'] as List<dynamic>;

    // 今日が基準の日から何日あとか。UTC の 0 時どうしで引く（ローカル時刻のままだと、
    // 間に夏時間の切り替えがあると 23 時間 × n になり、inDays の切り捨てで 1 日少なくなる）。
    final now = DateTime.now();
    final shiftDays = DateTime.utc(now.year, now.month, now.day)
        .difference(_baseDate)
        .inDays;

    // map: 1 件ずつ変換する（JavaScript の map）。最後に toList() でリストにする。
    return data.map((item) {
      final map = item as Map<String, dynamic>;
      return JobPosting.fromJson({
        // ...map: map の中身を全部ここに広げる（JavaScript のスプレッド構文 {...map}）。
        // 後ろに同じキーを書くと上書きされるので、work_date だけを今日までずらした値に替える。
        ...map,
        'work_date': _shiftDate(map['work_date'] as String, shiftDays),
      });
    }).toList();
  }

  // id で 1 件を探す。見つからなければ null（API なら 404 に当たる）。
  // 戻り値の型の ? は「JobPosting か null」（TypeScript の JobPosting | null）。
  Future<JobPosting?> findJobPosting(int id) async {
    // await で一覧が届くのを待ってから探す。
    // where で条件に合うものだけにし、firstOrNull で最初の 1 件（なければ null）を取る（Ruby の find）。
    final jobs = await fetchJobPostings();
    return jobs.where((job) => job.id == id).firstOrNull;
  }

  // "2026-01-05" を days 日ずらして、同じ形の文字列で返す。
  // DateTime(年, 月, 日 + days) は、日があふれても月をくり上げてくれる（1 月 35 日 → 2 月 4 日）。
  static String _shiftDate(String date, int days) {
    final d = DateTime.parse(date);
    final shifted = DateTime(d.year, d.month, d.day + days);
    // toIso8601String() は "2026-01-05T00:00:00.000" なので、先頭の 10 文字（日付の部分）だけを使う。
    return shifted.toIso8601String().substring(0, 10);
  }
}
