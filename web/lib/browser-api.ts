import type { FormState } from "./form-state";
import { toErrorDisplay } from "./schemas/common";
import { loginResponse } from "./schemas/user";

// ブラウザから Laravel を直接叩くのはログインとログアウトだけ（docs/01 5.2）。
// Cookie（セッション・XSRF-TOKEN）を受け取る／消す操作なので、Next サーバを経由しない。
// それ以外の API は lib/api.ts（サーバ側）を通す。

// NEXT_PUBLIC_ はビルド時に埋め込まれるので、process.env.NEXT_PUBLIC_API_URL と直接書く
function apiUrl(): string {
  const url = process.env.NEXT_PUBLIC_API_URL;
  if (!url) {
    throw new Error(
      "環境変数 NEXT_PUBLIC_API_URL が設定されていません（web/.env.example を参照）",
    );
  }
  return url;
}

const NETWORK_ERROR: FormState = { ok: false, message: "通信に失敗しました。" };

function readCookie(name: string): string {
  const prefix = `${name}=`;
  const found = document.cookie.split("; ").find((c) => c.startsWith(prefix));
  return found ? decodeURIComponent(found.slice(prefix.length)) : "";
}

/** fetch 自体の失敗（API が落ちている・CORS で拒否された）は TypeError になるので null を返す */
async function postWithCsrf(
  path: string,
  body?: unknown,
  redirect: RequestRedirect = "follow",
): Promise<Response | null> {
  const api = apiUrl();
  try {
    // XSRF-TOKEN Cookie を発行してもらう（204）
    await fetch(`${api}/sanctum/csrf-cookie`, { credentials: "include" });
    return await fetch(`${api}${path}`, {
      method: "POST",
      credentials: "include",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-XSRF-TOKEN": readCookie("XSRF-TOKEN"),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      redirect,
    });
  } catch (e) {
    if (e instanceof TypeError) {
      return null;
    }
    throw e;
  }
}

/** API の message をそのまま出すのは 422 / 429 / 404 だけ（lib/schemas/common.ts の toErrorDisplay） */
async function toFailure(res: Response): Promise<FormState> {
  const { message, errors } = toErrorDisplay(
    res.status,
    await res.json().catch(() => null),
  );
  return { ok: false, message, errors };
}

/** 200 → ok。302（既にログイン済み）→ ok。422 → errors.email、429 → 日本語の message（どちらも API の文言をそのまま返す） */
export async function login(
  email: string,
  password: string,
): Promise<FormState> {
  // ログイン済みのセッションで POST /login すると、Fortify の guest 判定が 302（API のトップへ）を返す。
  // 既定の redirect: "follow" だと fetch がその先を追って CORS で失敗し「通信に失敗しました。」になるので、
  // "manual" で止めて opaqueredirect（= 既にログイン済み）を成功として扱い /dashboard へ進ませる。
  // このとき入力した資格情報は確かめられず、今のセッションのまま進む（別アカウントへの切り替えはログアウトが先）
  const res = await postWithCsrf("/login", { email, password }, "manual");
  if (!res) {
    return NETWORK_ERROR;
  }
  if (res.type === "opaqueredirect") {
    return { ok: true };
  }
  if (!res.ok) {
    return toFailure(res);
  }
  loginResponse.parse(await res.json());
  return { ok: true };
}

/** 204 → ok。すでにセッションが切れている（401）ときもログアウト済みとして扱う */
export async function logout(): Promise<FormState> {
  const res = await postWithCsrf("/logout");
  if (!res) {
    return NETWORK_ERROR;
  }
  if (res.ok || res.status === 401) {
    return { ok: true };
  }
  return toFailure(res);
}
