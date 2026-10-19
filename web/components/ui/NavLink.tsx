"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { ReactNode } from "react";

// メニューのリンク。今の画面（href と同じか、その配下）なら aria-current="page" を付けるだけ。
// 見た目は呼ぶ側が className で決める（aria-[current=page]: で現在の画面を装飾する）

type NavLinkProps = {
  href: string;
  children: ReactNode;
  className?: string;
};

function isCurrent(pathname: string, href: string): boolean {
  return pathname === href || pathname.startsWith(`${href}/`);
}

export function NavLink({ href, children, className }: NavLinkProps) {
  const pathname = usePathname();
  return (
    <Link
      href={href}
      aria-current={isCurrent(pathname, href) ? "page" : undefined}
      className={className}
    >
      {children}
    </Link>
  );
}
