// 日付・時刻・金額の表示の形をそろえる関数。
// 形は docs/01 の 7A.4（web の 7.5 と同じ）: 2026/09/20（日）、09:00〜17:00、1,200円。
// Next.js で言うと web/ の lib/format.ts に当たる。
// 日付の書式のパッケージ（intl）は docs/01 の表にないので、手で書く。
//
// DateTime: https://api.dart.dev/dart-core/DateTime-class.html
// 文字列の補間（'$x' '${式}'）: https://dart.dev/language/built-in-types#strings

// 曜日。DateTime.weekday は 月曜 = 1 … 日曜 = 7 なので、[weekday - 1] で引く。
const _weekdays = ['月', '火', '水', '木', '金', '土', '日'];

// 2026/09/20（日）
String formatDate(DateTime date) {
  // padLeft(2, '0'): 2 桁に満たなければ前を 0 で埋める（9 → 09）。
  final month = date.month.toString().padLeft(2, '0');
  final day = date.day.toString().padLeft(2, '0');
  // '$x' で変数を、'${式}' で式の結果を文字列に埋め込む（JavaScript のテンプレート文字列 `${x}`）。
  return '${date.year}/$month/$day（${_weekdays[date.weekday - 1]}）';
}

// 09:00〜17:00（終了が開始より前なら深夜をまたぐ勤務。表示はそのまま並べる）
String formatTimeRange(String start, String end) => '$start〜$end';

// 1,200円
String formatYen(int amount) {
  // 3 桁ごとにカンマを入れる。正規表現で「後ろに 3 の倍数の桁が続く位置」を探して , を入れる。
  final digits = amount.toString().replaceAllMapped(
    RegExp(r'\B(?=(\d{3})+(?!\d))'),
    (_) => ',',
  );
  return '$digits円';
}
