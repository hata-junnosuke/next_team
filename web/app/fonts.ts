import { Barlow_Condensed, Noto_Sans_JP } from "next/font/google";

// app/layout.tsx と app/global-error.tsx の両方で <html> に variable を付ける（global-error はルートの layout の代わりに出るため）。
// 本文は Noto Sans JP（globals.css の --font-sans）。
// 時刻・人数・金額の数字だけ Barlow Condensed（--font-num。font-num ユーティリティで使う）
const notoSansJp = Noto_Sans_JP({
  variable: "--font-sans-jp",
  subsets: ["latin"],
  display: "swap",
});

const barlowCondensed = Barlow_Condensed({
  variable: "--font-num-condensed",
  subsets: ["latin"],
  weight: ["500", "600", "700"],
  display: "swap",
});

export const fontVariables = `${notoSansJp.variable} ${barlowCondensed.variable}`;
