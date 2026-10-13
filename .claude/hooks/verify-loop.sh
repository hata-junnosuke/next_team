#!/usr/bin/env bash
# Stop フック = 検証ループ（最内ループ）。
#   api: Pint → PHPUnit            （常に回す。速いので毎回）
#   web: tsc --noEmit → ESLint     （web/ に変更があるときだけ。next build は遅いので /verify full に任せる）
#   受け入れ条件（必須テストファイルの存在。フェーズで増える）
#   赤なら {"decision":"block","reason":"..."} で Claude を止めずに修正させる。
#   ハードストップ: 最大 MAX 回（.claude/.loop-count）。上限に達したら素通しして人に報告させる。
#
# stop_hook_active の扱い（重要）:
#   true  = このフックの差し戻しで Claude が継続した後の Stop。カウンタを維持して周回を数える
#   false = ユーザー起点の新しい終了。カウンタを 0 に戻す
#   ※ true で即 exit すると 1 周しか回らないので、そうしない。
#
# 第2弾との違い:
#   - フェーズ 0 でも回す（ゲート確認で「わざと赤にして差し戻される」ことを確かめるため）
#   - 2 スタック。web は「変更があるときだけ」検査する（判断材料は git status と .claude/.dirty-web）
set -u

# PHPUnit / Pint は非 TTY では JSON を出す（laravel/pao）。人が読める行に整形する
format_phpunit() {
  if printf '%s' "$1" | grep -q '"tool":"phpunit"'; then
    printf '%s' "$1" | jq -r '"  " + (.failed|tostring) + " 件失敗 / " + (.tests|tostring) + " 件中", (.failures[]? | "  FAILED " + .test + "\n    " + (.message | split("\n")[0:3] | join(" ")) + "\n    " + .file + ":" + (.line|tostring))' 2>/dev/null | head -60
  else
    printf '%s' "$1" | grep -vE '^\s*$' | tail -40
  fi
}
format_pint() {
  if printf '%s' "$1" | grep -q '"tool":"pint"'; then
    printf '%s' "$1" | jq -r '.files[]? | "  " + .path + "  (" + (.fixers | join(", ")) + ")"' 2>/dev/null | head -20
  else
    printf '%s' "$1" | grep -E '⨯|\.php' | head -20
  fi
}

ROOT="${CLAUDE_PROJECT_DIR:-$PWD}"
COUNTER="$ROOT/.claude/.loop-count"
DIRTY_WEB="$ROOT/.claude/.dirty-web"
LOG="$ROOT/.claude/state/loop-log.md"
MAX=5
INPUT="$(cat)"
ACTIVE="$(printf '%s' "$INPUT" | jq -r '.stop_hook_active // false')"
PHASE="$(cat "$ROOT/.claude/.phase" 2>/dev/null || echo 0)"
cd "$ROOT" || exit 0

# (0) どちらのスタックも依存未導入なら素通し
HAS_API=0; [ -x api/vendor/bin/pint ] && [ -f api/vendor/bin/phpunit ] && HAS_API=1
HAS_WEB=0; [ -x web/node_modules/.bin/tsc ] && HAS_WEB=1
if [ "$HAS_API" = 0 ] && [ "$HAS_WEB" = 0 ]; then
  rm -f "$COUNTER"; exit 0
fi

# (0.5) 担当（サブエージェント）が動いている間は検査しない。
#   リードは担当の完了通知を待つために応答を終えるので、ここで全テストを回すと TDD の途中の赤で差し戻してしまう。
#   最後の担当が終わるとリードが通知で再開し、その応答の終わりには印がないので必ず検査される。
#   印は subagent-start.sh が置き、subagent-check.sh が消す。
#   数えるのは「60 分以内に置かれた通常ファイル」だけ（ディレクトリやリンク、異常終了で残った古い印では飛ばさない）。
#   飛ばしたことは systemMessage で必ず見えるようにする（黙って通さない）
RUNNING_DIR="$ROOT/.claude/.running"
if [ -d "$RUNNING_DIR" ]; then
  find "$RUNNING_DIR" -maxdepth 1 -type f -mmin +60 -delete 2>/dev/null
  RUNNING="$(find "$RUNNING_DIR" -maxdepth 1 -type f -mmin -60 2>/dev/null | sed 's|.*/||' | tr '\n' ' ')"
  if [ -n "$RUNNING" ]; then
    N="$(printf '%s' "$RUNNING" | wc -w | tr -d ' ')"
    jq -n --arg m "検証ループ: 担当 ${N} 体が稼働中のため検査を保留しました（${RUNNING% }）。最後の担当が終わった後の終了で検査します。担当が中断されて印が残った場合は .claude/.running/ を消してください。" '{systemMessage:$m}'
    exit 0
  fi
fi

# web を検査するか: 編集の印がある / git が web/ の変更を報告している
CHECK_WEB=0
if [ "$HAS_WEB" = 1 ]; then
  [ -f "$DIRTY_WEB" ] && CHECK_WEB=1
  if [ "$CHECK_WEB" = 0 ] && git rev-parse --git-dir >/dev/null 2>&1; then
    [ -n "$(git status --short -- web 2>/dev/null | head -1)" ] && CHECK_WEB=1
  fi
fi

# (1) ユーザー起点の終了ならカウンタをリセット
[ "$ACTIVE" = "true" ] || echo 0 > "$COUNTER"
COUNT="$(cat "$COUNTER" 2>/dev/null || echo 0)"

# (2) ハードストップ
if [ "$COUNT" -ge "$MAX" ]; then
  rm -f "$COUNTER"
  printf -- "- %s | フェーズ %s | 打ち切り | 検証ループが %d 回に達したため素通し。人の判断が必要\n" "$(date '+%Y-%m-%d %H:%M')" "$PHASE" "$MAX" >> "$LOG"
  echo "検証ループが ${MAX} 回に達したため打ち切りました。残った失敗を人に報告し、作業中の issue に requires-human ラベルを付けて理由をコメントしてください。" >&2
  exit 0
fi

# (3) 検査
FAIL=""
if [ "$HAS_API" = 1 ]; then
  PINT_OUT="$(cd api && vendor/bin/pint --test 2>&1)" || FAIL="${FAIL}## api: Pint 失敗（make api-pint で整形）
$(format_pint "$PINT_OUT")

"
  TEST_OUT="$(cd api && php artisan test --compact 2>&1)" || FAIL="${FAIL}## api: PHPUnit 失敗（make api-test FILTER=<テスト名> で再現）
$(format_phpunit "$TEST_OUT")

"
fi
if [ "$CHECK_WEB" = 1 ]; then
  TSC_OUT="$(cd web && node_modules/.bin/tsc --noEmit 2>&1)" || FAIL="${FAIL}## web: 型エラー（make web-typecheck で再現）
$(printf '%s' "$TSC_OUT" | head -30)

"
  LINT_OUT="$(cd web && node_modules/.bin/eslint . 2>&1)" || FAIL="${FAIL}## web: ESLint 失敗（make web-lint で再現）
$(printf '%s' "$LINT_OUT" | grep -vE '^\s*$' | head -30)

"
fi

# 受け入れ条件（必須テストファイル）。ファイル名は docs/01_技術仕様_next.md 9 章の契約
require() { [ -f "$1" ] || FAIL="${FAIL}## 受け入れ条件: $1 がありません（$2）
"; }
if [ "$PHASE" -ge 3 ]; then
  require api/tests/Feature/ApplicationTest.php "要件 7 章: 定員超過の拒否"
  require api/tests/Unit/PayrollCalculatorTest.php "要件 7 章: 報酬計算"
  require api/tests/Unit/ApplicationStatusTest.php "要件 7 章: 状態遷移の拒否"
  require api/tests/Feature/AcceptanceScenarioTest.php "要件 5 章: 動線 10 ステップ（API 経由）"
fi
if [ "$PHASE" -ge 5 ]; then
  require api/tests/Feature/NotificationIdempotencyTest.php "要件 7 章: 同じ通知を二重に送らない"
  require api/tests/Feature/FailedJobTest.php "要件 7 章: 失敗が記録され再実行できる"
fi

# (4) 差し戻し
if [ -n "$FAIL" ]; then
  COUNT=$((COUNT + 1)); echo "$COUNT" > "$COUNTER"
  SUMMARY="$(printf '%s' "$FAIL" | grep -E '^## ' | sed -e 's/^## //' -e 's/（.*//' | tr '\n' ' ')"
  printf -- "- %s | フェーズ %s | %d/%d 回目 | %s\n" "$(date '+%Y-%m-%d %H:%M')" "$PHASE" "$COUNT" "$MAX" "$SUMMARY" >> "$LOG"
  jq -n --arg r "検証ループ ${COUNT}/${MAX}: 以下を修正してから終了してください。修正後にもう一度終了すると再検証します。

${FAIL}" '{decision:"block", reason:$r}'
  exit 0
fi

# (5) 成功
if [ "$COUNT" -gt 0 ]; then
  printf -- "- %s | フェーズ %s | 完了 | %d 回の差し戻しの後に緑\n" "$(date '+%Y-%m-%d %H:%M')" "$PHASE" "$COUNT" >> "$LOG"
fi
rm -f "$COUNTER" "$DIRTY_WEB"
exit 0
