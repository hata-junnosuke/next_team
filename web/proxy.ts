import { NextResponse, type NextRequest } from "next/server";

// Next 16 で middleware.ts から proxy.ts に改名された（node_modules/next/dist/docs/01-app/01-getting-started/16-proxy.md）。
// ここは楽観的な検査だけ: セッション Cookie が無ければ /login へ送る。
// Cookie があっても期限切れのことがあるので、本当の判定は lib/api.ts が 401 を受けたときの redirect("/login")。
// /login は対象外（期限切れの Cookie で /login ⇄ /dashboard を往復しないように）

// api/.env の APP_NAME=next_team から Laravel が付ける名前（Str::slug(APP_NAME) + "-session"。ハイフン区切り）
const SESSION_COOKIE = "next-team-session";

export function proxy(request: NextRequest) {
  if (request.cookies.has(SESSION_COOKIE)) {
    return NextResponse.next();
  }
  return NextResponse.redirect(new URL("/login", request.url));
}

export const config = {
  // /login（と /login/ 以下）と静的ファイル以外のすべての画面。
  // login(?:/|$) にしているのは、/login-history のような別の画面まで素通りさせないため
  matcher: [
    "/((?!login(?:/|$)|_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|ico)$).*)",
  ],
};
