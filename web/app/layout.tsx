import type { Metadata } from "next";
import { fontVariables } from "./fonts";
import "./globals.css";

export const metadata: Metadata = {
  title: "myteam 管理画面",
  description: "企業側管理画面（拠点・ワーカー・募集・応募・勤怠・報酬）",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="ja" className={`${fontVariables} h-full antialiased`}>
      <body className="bg-canvas text-ink flex min-h-full flex-col font-sans tabular-nums">
        {children}
      </body>
    </html>
  );
}
