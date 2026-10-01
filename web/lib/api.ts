import "server-only";

import { cookies } from "next/headers";
import { notFound, redirect } from "next/navigation";
import type { z } from "zod";
import type { FormState } from "./form-state";
import { toErrorDisplay } from "./schemas/common";

// サーバ側（Server Component / Server Action / Route Handler）から Laravel を叩く唯一の入口（docs/01 7.2）。
// ブラウザの Cookie は Next サーバの fetch には自動で付かないので、ここで転送する。

/** 401 以外の失敗。422 の項目別エラーは errors に入る。タイムアウトは status 0 */
export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;

  constructor(
    status: number,
    message: string,
    errors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
  }
}

type Method = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

/** Laravel の応答を待つ上限（ミリ秒） */
const REQUEST_TIMEOUT_MS = 15_000;

function env(name: "API_URL" | "WEB_ORIGIN"): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(
      `環境変数 ${name} が設定されていません（web/.env.example を参照）`,
    );
  }
  return value;
}

/** Laravel を叩いて Response を返す。401 はここで /login へ送る。それ以外の失敗は ApiError */
export async function apiFetch(
  method: Method,
  path: string,
  body?: unknown,
): Promise<Response> {
  const store = await cookies();
  const origin = env("WEB_ORIGIN");

  const headers: Record<string, string> = {
    Accept: "application/json",
    // Sanctum はこのどちらかが SANCTUM_STATEFUL_DOMAINS に一致したときだけセッション認証を使う
    Origin: origin,
    Referer: `${origin}/`,
    Cookie: store.toString(),
  };
  if (method !== "GET") {
    // cookies() の値は URL デコード済み（= decodeURIComponent した値）なので、そのまま付ける
    const xsrf = store.get("XSRF-TOKEN")?.value;
    if (xsrf) {
      headers["X-XSRF-TOKEN"] = xsrf;
    }
  }
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
  }

  let res: Response;
  try {
    res = await fetch(`${env("API_URL")}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      // 管理画面は常に最新を見る
      cache: "no-store",
      // API が応答しないときに画面やフォームが待ち続けないよう打ち切る
      signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
    });
  } catch (e) {
    // AbortSignal.timeout は TimeoutError（DOMException）で打ち切る。中身は出さず固定文言にする
    if (e instanceof DOMException && e.name === "TimeoutError") {
      throw new ApiError(0, "通信に失敗しました（タイムアウト）。");
    }
    throw e;
  }

  if (res.status === 401) {
    // redirect は例外を投げる。Server Action の catch で受けても toFormState が投げ直す
    redirect("/login");
  }
  if (!res.ok) {
    throw await toApiError(res);
  }
  return res;
}

async function toApiError(res: Response): Promise<ApiError> {
  const json: unknown = await res.json().catch(() => null);
  const { message, errors } = toErrorDisplay(res.status, json);
  return new ApiError(res.status, message, errors);
}

/** GET して schema で parse した結果を返す。404（存在しない・他社のデータ）は not-found 画面へ */
export async function apiGet<T>(
  path: string,
  schema: z.ZodType<T>,
): Promise<T> {
  let res: Response;
  try {
    res = await apiFetch("GET", path);
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) {
      notFound();
    }
    throw e;
  }
  return schema.parse(await res.json());
}

/** 更新系。schema を渡せば本文を parse して返す。渡さなければ本文を読まない（204 など） */
export async function apiSend(
  method: Exclude<Method, "GET">,
  path: string,
  body?: unknown,
): Promise<void>;
export async function apiSend<T>(
  method: Exclude<Method, "GET">,
  path: string,
  body: unknown,
  schema: z.ZodType<T>,
): Promise<T>;
export async function apiSend<T>(
  method: Exclude<Method, "GET">,
  path: string,
  body?: unknown,
  schema?: z.ZodType<T>,
): Promise<T | void> {
  const res = await apiFetch(method, path, body);
  if (!schema) {
    return;
  }
  return schema.parse(await res.json());
}

/**
 * Server Action の catch で使う。422 は FormState にして返し、送った値を values に詰める
 * （React 19 の <form action> は終了時にフォームをリセットするので、入力欄は
 * defaultValue={state.values?.[name] ?? 元の値} で戻す）。
 * それ以外（404 / 500 / redirect の例外）は投げ直す。検証は Laravel が正。web 側で同じ検証をしない
 */
export function toFormState(e: unknown, formData: FormData): FormState {
  if (e instanceof ApiError && e.status === 422) {
    return {
      ok: false,
      message: e.message,
      errors: e.errors,
      values: submittedValues(formData),
    };
  }
  throw e;
}

/** パスワードと Next が内部で付ける $ACTION_ のキーは返さない。ファイルも返さない */
function submittedValues(formData: FormData): Record<string, string> {
  const values: Record<string, string> = {};
  for (const [key, value] of formData.entries()) {
    if (
      key === "password" ||
      key.startsWith("$ACTION_") ||
      typeof value !== "string"
    ) {
      continue;
    }
    values[key] = value;
  }
  return values;
}
