---
paths:
  - "web/app/**"
  - "web/components/**"
  - "web/lib/**"
  - "web/proxy.ts"
---
# Next.js 実装ルール（`web/` を編集するときに適用）

- **Next.js 16 は学習データと違う**。書く前に `web/node_modules/next/dist/docs/01-app/` の該当ページを読む（`middleware.ts` → `proxy.ts`、`cookies()` は `await`、`fetch` は既定でキャッシュされない、`params` / `searchParams` は Promise）
- Prettier 準拠（PostToolUse フックが自動整形）。TypeScript は strict。`any` を書かない
- **`fetch` を直接書かない**。サーバ側は `lib/api.ts` の `apiGet` / `apiSend`、ブラウザ側は `lib/browser-api.ts`（ログイン・ログアウトだけ）
- API のレスポンスは必ず zod で `parse` する。型は `z.infer` で導出し、**手書きの型定義（`type Worker = {...}`）を置かない**
- 既定は Server Component。`"use client"` は `useActionState` などフックが要るコンポーネントだけに付ける
- 更新は Server Action（`lib/actions/*.ts`、先頭に `"use server"`）。フォームは `useActionState`
- 検証は Laravel を正とする。422 の `errors` を `FormState` にして `Field` に流す。同じ検証を web に二重に書かない
- 状態管理ライブラリ・データ取得ライブラリ・UI ライブラリを入れない
- 共通部品は `components/ui/` の 7 つ（`Table` `Field` `Button` `Alert` `Badge` `Pagination` `LogoutButton`）。足したくなったらリードに報告する
- 状態のラベルは API の `label` をそのまま出す。色は `lib/status.ts` の対応表だけ
- 表のセル・バッジ・ボタンには `whitespace-nowrap`
- `api/` のコードを読まない。契約は `docs/01_技術仕様_next.md` 6 章と実際のレスポンス
- 詳細は `.claude/skills/next-coding-standards/SKILL.md`
