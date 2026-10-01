import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "myteam 管理画面",
  description: "企業側管理画面（拠点・ワーカー・募集・応募・勤怠・報酬）",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="ja" className="h-full antialiased">
      <body className="flex min-h-full flex-col">
        <div className="flex min-h-full flex-1 flex-col bg-gray-50 text-gray-900">
          {children}
        </div>
      </body>
    </html>
  );
}
