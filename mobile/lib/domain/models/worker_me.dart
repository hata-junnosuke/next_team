// ログイン中のワーカー自身の情報（API の WorkerMeResource。docs/01 の 6.11）。
// ログイン・初回登録の応答と、GET /api/worker/me の応答がこの形になる。
// TypeScript で言うと、zod のスキーマ（z.object({...})）と、そこから作った型の組み合わせ。
// Step 7 からは freezed と json_serializable で生成するが、Step 6 は手書き（docs/01 の 7A.4）。
//
// domain/ なので Flutter も Riverpod も import しない（docs/01 の 7A.1）。
//
// JSON を手で読む: https://docs.flutter.dev/data-and-backend/serialization/json

// ワーカーが所属する企業（WorkerMeResource の company: {id, name, code}）。
class WorkerCompany {
  const WorkerCompany({
    required this.id,
    required this.name,
    required this.code,
  });

  // 形が合わなければ FormatException を投げる。黙って null や空文字にせず、契約（6.11）とのずれとして気づけるようにする。
  // as int と書くと、合わないときに TypeError（Error = プログラムのバグの扱い）になり、
  // 画面の「応答の形が想定と違います」の catch（on FormatException）で受けられない。
  // だから switch のパターンで形を確かめ、合わなければ FormatException にそろえる（api_error.dart と同じ書き方）。
  // Map のパターン {'id': final int id, ...}: キーがあり、値がその型のときだけ当たり、値を変数に取り出す。
  // 例外の文言には応答の値（氏名・メール）を入れず、キーだけを書く（個人情報を例外やログに残さない）。
  // https://dart.dev/language/patterns
  factory WorkerCompany.fromJson(Map<String, dynamic> json) {
    return switch (json) {
      {
        'id': final int id,
        'name': final String name,
        'code': final String code,
      } =>
        WorkerCompany(id: id, name: name, code: code),
      _ => throw FormatException('WorkerMeResource.company の形が違います（キー: ${json.keys.toList()}）'),
    };
  }

  final int id;
  final String name;
  // 企業コード（大文字の 8 文字ほど）。次のログインの入力欄に入れておくために保存する（7A.4。T8）。
  final String code;
}

class WorkerMe {
  const WorkerMe({
    required this.id,
    required this.name,
    required this.email,
    required this.company,
  });

  // 形が合わなければ FormatException（WorkerCompany.fromJson と同じ理由）。
  // email は API が小文字にそろえて保存したメール（6.11）。
  factory WorkerMe.fromJson(Map<String, dynamic> json) {
    return switch (json) {
      {
        'id': final int id,
        'name': final String name,
        'email': final String email,
        'company': final Map<String, dynamic> company,
      } =>
        WorkerMe(
          id: id,
          name: name,
          email: email,
          company: WorkerCompany.fromJson(company),
        ),
      _ => throw FormatException('WorkerMeResource の形が違います（キー: ${json.keys.toList()}）'),
    };
  }

  final int id;
  final String name;
  final String email;
  final WorkerCompany company;
}
