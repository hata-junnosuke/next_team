// 偽のリポジトリ。コードに直書きした 10 件の募集を返す。
// リポジトリ = 「データをどこから取るか」を画面から隠す層。画面は「募集の一覧をちょうだい」と頼むだけで、
// 相手が偽物か本物の API かを知らない。Step 7 で ApiJobRepository（API を呼ぶ）に差し替える。
// Rails で言うと、コントローラが ActiveRecord を直接呼ばずにサービスを通すのに近い。
// Next.js で言うと web/ の lib/api.ts（画面は fetch を直接書かない）に当たる。
//
// なぜリポジトリ層を挟むか（公式の構成ガイド）: https://docs.flutter.dev/app-architecture/guide

// DateUtils（日付だけを取り出す道具）を使うために読み込む。
import 'package:flutter/material.dart';

import '../domain/job_posting.dart';

class FakeJobRepository {
  const FakeJobRepository();

  // 拠点は 3 つ。static const: インスタンスを作らずに使える、クラスに 1 つだけの定数（Ruby のクラス定数）。
  // 名前の先頭の _ は「このファイルの外から見えない」印（Ruby の private。Dart には private キーワードがない）。
  static const _shibuya = JobLocation(id: 1, name: '渋谷倉庫');
  static const _shinjuku = JobLocation(id: 2, name: '新宿店');
  static const _shinagawa = JobLocation(id: 3, name: '品川センター');

  // 募集の一覧。勤務日 → 開始時刻の順に並べて返す（API の並び順と同じ。docs/01 の 6.11）。
  // 残り枠（remaining）は API が数えて返す値の代わり。4 と 8 は満員（残り 0）にしてある。
  // List<JobPosting>: JobPosting だけが入るリスト（TypeScript の JobPosting[]）。
  List<JobPosting> fetchJobPostings() {
    // 勤務日は「今日から何日後か」で作る。固定の日付にすると、日がたつと全部過去の募集になってしまうため。
    final today = DateUtils.dateOnly(DateTime.now());
    // 関数の中に書いた関数（ローカル関数）。引数の day は「今日から何日後か」。
    DateTime daysLater(int day) => today.add(Duration(days: day));

    return [
      JobPosting(
        id: 1,
        location: _shibuya,
        jobTitle: '倉庫内ピッキング',
        workDate: daysLater(0),
        startTime: '09:00',
        endTime: '17:00',
        hourlyWage: 1200,
        transportAllowance: 500,
        capacity: 5,
        remaining: 3,
      ),
      JobPosting(
        id: 2,
        location: _shinjuku,
        jobTitle: '品出し',
        workDate: daysLater(0),
        startTime: '18:00',
        endTime: '22:00',
        hourlyWage: 1300,
        transportAllowance: 0,
        capacity: 3,
        remaining: 1,
      ),
      JobPosting(
        id: 3,
        location: _shinagawa,
        jobTitle: '検品',
        workDate: daysLater(1),
        startTime: '08:00',
        endTime: '12:00',
        hourlyWage: 1150,
        transportAllowance: 400,
        capacity: 4,
        remaining: 4,
      ),
      JobPosting(
        id: 4,
        location: _shibuya,
        jobTitle: '梱包',
        workDate: daysLater(1),
        startTime: '13:00',
        endTime: '18:00',
        hourlyWage: 1200,
        transportAllowance: 500,
        capacity: 2,
        remaining: 0,
      ),
      JobPosting(
        id: 5,
        location: _shinjuku,
        jobTitle: 'レジ補助',
        workDate: daysLater(2),
        startTime: '10:00',
        endTime: '15:00',
        hourlyWage: 1250,
        transportAllowance: 0,
        capacity: 3,
        remaining: 2,
      ),
      JobPosting(
        id: 6,
        location: _shinagawa,
        jobTitle: '仕分け',
        workDate: daysLater(3),
        startTime: '22:00',
        endTime: '05:00',
        hourlyWage: 1500,
        transportAllowance: 600,
        capacity: 6,
        remaining: 5,
      ),
      JobPosting(
        id: 7,
        location: _shibuya,
        jobTitle: '倉庫内ピッキング',
        workDate: daysLater(4),
        startTime: '09:00',
        endTime: '17:00',
        hourlyWage: 1200,
        transportAllowance: 500,
        capacity: 5,
        remaining: 5,
      ),
      JobPosting(
        id: 8,
        location: _shinjuku,
        jobTitle: '品出し',
        workDate: daysLater(6),
        startTime: '07:00',
        endTime: '11:00',
        hourlyWage: 1300,
        transportAllowance: 0,
        capacity: 2,
        remaining: 0,
      ),
      JobPosting(
        id: 9,
        location: _shinagawa,
        jobTitle: '検品',
        workDate: daysLater(9),
        startTime: '13:00',
        endTime: '17:00',
        hourlyWage: 1150,
        transportAllowance: 400,
        capacity: 3,
        remaining: 3,
      ),
      JobPosting(
        id: 10,
        location: _shibuya,
        jobTitle: '棚卸し',
        workDate: daysLater(12),
        startTime: '20:00',
        endTime: '23:00',
        hourlyWage: 1400,
        transportAllowance: 500,
        capacity: 4,
        remaining: 1,
      ),
    ];
  }

  // id で 1 件を探す。見つからなければ null（API なら 404 に当たる）。
  // 戻り値の型の ? は「JobPosting か null」（TypeScript の JobPosting | null）。
  JobPosting? findJobPosting(int id) {
    // where で条件に合うものだけにし、firstOrNull で最初の 1 件（なければ null）を取る（Ruby の find）。
    return fetchJobPostings().where((job) => job.id == id).firstOrNull;
  }
}
