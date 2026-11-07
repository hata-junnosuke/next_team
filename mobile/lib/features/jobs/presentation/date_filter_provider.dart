// 一覧で選んでいる日付のチップ（すべて / 今日 / 明日 / 今週）を持つ Provider。
// T6 までは一覧の画面の State（setState）が持っていた。Provider に移すと、画面は読んで描くだけになり、
// ほかの画面や Provider からも「今どのチップか」を読めるようになる。
// React で言うと、useState を Zustand の store（または URL の検索パラメータ）に移したのに近い。
//
// Notifier: https://riverpod.dev/ja/docs/concepts2/providers

import 'package:riverpod_annotation/riverpod_annotation.dart';

import 'date_filter.dart';

part 'date_filter_provider.g.dart';

// 値（今のチップ）と、それを変えるメソッド（select）を持つので、クラスの @riverpod（Notifier）にする（T4 と同じ形）。
// 生成される Provider の名前は selectedDateFilterProvider。
//
// 何を持つか: 選んでいる DateFilter（最初は「すべて」）。
// いつ作り直されるか: keepAlive を付けていないので、読む画面がなくなると捨てられ、次は「すべて」から始まる。
// ただし「さがす」タブは indexedStack で残り、一覧の画面が watch し続けるので、アプリを動かしている間は残る。
// 応募済み（keepAlive）と違い、捨てて「すべて」に戻っても困らないので、既定のままにする。
@riverpod
class SelectedDateFilter extends _$SelectedDateFilter {
  @override
  DateFilter build() => DateFilter.all;

  // enum は値そのものを入れ替えるだけでよい（Set のように中身を書き換える心配がない）。
  void select(DateFilter filter) {
    state = filter;
  }
}
