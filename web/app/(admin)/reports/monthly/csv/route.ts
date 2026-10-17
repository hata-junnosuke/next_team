import type { NextRequest } from "next/server";
import { ApiError, apiFetch } from "@/lib/api";

// 月次集計の CSV（F-18。同期）の中継。ブラウザは Laravel を直接叩かないので、
// Next サーバが Cookie を転送して GET /api/reports/monthly/csv を取り、本文とヘッダを返す（docs/01 の設計判断と理由）。
// 401 は apiFetch が /login へ redirect する。month の検証は Laravel が正

/** Laravel の応答から引き継ぐヘッダ（docs/01 の API 契約の月次集計） */
const PASS_HEADERS = ["Content-Type", "Content-Disposition"];

function errorResponse(status: number, message: string): Response {
  return new Response(message, {
    status,
    headers: {
      "Content-Type": "text/plain; charset=UTF-8",
      "X-Content-Type-Options": "nosniff",
    },
  });
}

export async function GET(request: NextRequest) {
  const month = request.nextUrl.searchParams.get("month") ?? "";

  let res: Response;
  let body: ArrayBuffer;
  try {
    res = await apiFetch(
      "GET",
      `/api/reports/monthly/csv?month=${encodeURIComponent(month)}`,
    );
    // 本文を読み切ってから返す。ストリームのまま渡すと、apiFetch の 15 秒のタイムアウトで
    // 途中で切れた CSV が 200 のままブラウザに届いてしまう。BOM や改行はいじらない
    body = await res.arrayBuffer();
  } catch (e) {
    // 本文の読み取り中のタイムアウトは DOMException（TimeoutError）で来る
    if (
      (e instanceof ApiError && e.status === 0) ||
      (e instanceof DOMException && e.name === "TimeoutError")
    ) {
      return errorResponse(504, "通信に失敗しました（タイムアウト）。");
    }
    // 422（month が不正）や 404 などは、表示してよい message だけ（toErrorDisplay 済み）を文字で返す
    if (e instanceof ApiError) {
      return errorResponse(e.status, e.message);
    }
    throw e;
  }

  const headers = new Headers({
    "Cache-Control": "no-store",
    "X-Content-Type-Options": "nosniff",
  });
  for (const name of PASS_HEADERS) {
    const value = res.headers.get(name);
    if (value) {
      headers.set(name, value);
    }
  }
  return new Response(body, { status: res.status, headers });
}
