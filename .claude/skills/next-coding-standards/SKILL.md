---
name: next-coding-standards
description: myteam 第3弾の Next.js 16 実装規約。Server Component でのデータ取得、lib/api.ts 経由の Cookie 転送、zod スキーマ、Server Action と useActionState、422 の表示、共通部品、ログインの流れ。implementer-web と code-reviewer が従う
---

# Next.js 実装規約（myteam 第3弾）

契約（ディレクトリ・関数の形・画面と API の対応）は `docs/01_技術仕様_next.md` 7 章が正。ここは書き方。

**書く前に `web/node_modules/next/dist/docs/01-app/` を読む。** Next 16 は以前の版と違う。下のコードは形を示すもので、API の細部は同梱ドキュメントを正とする。

## 以前の版との主な違い
| 以前 | Next 16 |
|---|---|
| `middleware.ts` / `export function middleware` | `proxy.ts` / `export function proxy` |
| `cookies().get()` | `(await cookies()).get()` |
| `params.id` | `const { id } = await params` |
| `fetch` は既定でキャッシュ | 既定でキャッシュされない |

## 1 機能を作る順番
1. **スキーマ** `lib/schemas/worker.ts`
   ```ts
   export const workerSchema = z.object({
     id: z.number(), name: z.string(), email: z.string(),
     phone: z.string().nullable(), is_active: z.boolean(),
   });
   export const workerResponse = z.object({ data: workerSchema });
   export const workerListResponse = paginated(workerSchema);   // lib/schemas/common.ts
   export type Worker = z.infer<typeof workerSchema>;            // 型はここからだけ作る
   ```
2. **一覧ページ**（Server Component） `app/(admin)/workers/page.tsx`
   ```tsx
   export default async function Page({ searchParams }: { searchParams: Promise<{ q?: string; page?: string }> }) {
     const { q = "", page = "1" } = await searchParams;
     const { data, meta } = await apiGet(`/api/workers?q=${encodeURIComponent(q)}&page=${page}`, workerListResponse);
     return <Table .../>;
   }
   ```
3. **Server Action** `lib/actions/worker.ts`
   ```ts
   "use server";
   export async function createWorker(_prev: FormState, formData: FormData): Promise<FormState> {
     try {
       await apiSend("POST", "/api/workers", Object.fromEntries(formData));
     } catch (e) {
       return toFormState(e);          // 422 → {message, errors}。それ以外は投げ直す
     }
     revalidatePath("/workers");
     redirect("/workers");             // redirect は try の外で呼ぶ
   }
   ```
4. **フォーム**（Client Component） `components/workers/WorkerForm.tsx`
   ```tsx
   "use client";
   const [state, action, pending] = useActionState(createWorker, {});
   return (
     <form action={action}>
       <Alert state={state} />
       <Field label="氏名" name="name" errors={state.errors?.name} defaultValue={worker?.name} />
       <Button pending={pending}>登録する</Button>
     </form>
   );
   ```

## 守ること
- `fetch(` を書いてよいのは `lib/api.ts` と `lib/browser-api.ts` の中だけ
- `lib/api.ts` は `import "server-only"`。Client Component から import するとビルドが落ちる（それでよい）
- レスポンスは必ず zod で `parse`。**API が契約と違う形を返したら、ここで例外になって気づける**。web 側で形を合わせにいかない
- 型は `z.infer` だけ。`type` や `interface` を手で書くのは props と `FormState` だけ
- 検証を二重に書かない。必須チェックや範囲チェックは Laravel が 422 で返すものを表示する
- 業務ルールを書かない。ボタンを出すかどうかは API の `can` を見る。定員に達したかどうかを web で計算しない
- 状態ボタン（承認・却下・キャンセル）は小さなフォーム + Server Action。成功したら `revalidatePath` だけして同じページに留まる
- 数値の入力は `FormData` では文字列で届く。Laravel 側の検証（`integer`）が数値文字列を受け付けるので、web で変換しない

## ログイン（`app/(auth)/login/page.tsx` と `lib/browser-api.ts`）
ここだけブラウザから Laravel を直接叩く。Cookie を受け取る操作だから。
```ts
await fetch(`${API}/sanctum/csrf-cookie`, { credentials: "include" });
const xsrf = decodeURIComponent(readCookie("XSRF-TOKEN"));
const res = await fetch(`${API}/login`, {
  method: "POST", credentials: "include",
  headers: { "Content-Type": "application/json", Accept: "application/json", "X-XSRF-TOKEN": xsrf },
  body: JSON.stringify({ email, password }),
});
// 200 → router.push("/dashboard") / 422 → errors.email を表示 / 429 → 「しばらく待ってから…」
```
ログアウトも同じ形で `POST /logout`、成功したら `/login` へ。

## `proxy.ts`
セッション Cookie（`next_team_session`）がなければ `/login` へ送るだけ。Cookie があっても期限切れのことがあるので、本当の判定は `lib/api.ts` が 401 を受けたときの `redirect("/login")`。

## 共通部品（`components/ui/`）
| 部品 | 役割 |
|---|---|
| `Table` | 見出しと行。セルは `whitespace-nowrap` |
| `Field` | ラベル + 入力 + その項目のエラー。`type` は text / email / number / date / time / select |
| `Button` | `pending` で無効化と文言の切り替え。`variant`: primary / secondary / danger |
| `Alert` | `FormState` の `message` を表示（成功 / エラー） |
| `Badge` | 状態の表示。色は `lib/status.ts` |
| `Pagination` | `meta` を受けて前後のリンク |

## 表示（`lib/format.ts`）
- `yen(9600)` → `9,600円` / `minutes(420)` → `7時間00分` / `date("2026-09-20")` → `2026/09/20（日）`
- `Intl.NumberFormat("ja-JP")` と `Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo" })` を使う。日付ライブラリは入れない

## 確認の仕方
- `make web-typecheck` と `make web-lint`（Stop フックも同じものを回す）
- 画面は `make api-serve` と `make web-dev` を起動して http://localhost:3002 で見る。**3000 ではない**
