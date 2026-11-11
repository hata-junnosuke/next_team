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

  // 形が合わなければ as のところで TypeError（例外）になる。
  // 黙って null や空文字にせず、契約（6.11）とのずれとして気づけるようにする（job_posting.dart と同じ）。
  factory WorkerCompany.fromJson(Map<String, dynamic> json) {
    return WorkerCompany(
      id: json['id'] as int,
      name: json['name'] as String,
      code: json['code'] as String,
    );
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

  factory WorkerMe.fromJson(Map<String, dynamic> json) {
    return WorkerMe(
      id: json['id'] as int,
      name: json['name'] as String,
      // API が小文字にそろえて保存したメール（6.11）。
      email: json['email'] as String,
      company: WorkerCompany.fromJson(json['company'] as Map<String, dynamic>),
    );
  }

  final int id;
  final String name;
  final String email;
  final WorkerCompany company;
}
