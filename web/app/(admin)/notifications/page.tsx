import {
  NotificationFilters,
  type NotificationFilterValues,
} from "@/components/notifications/NotificationFilters";
import { NotificationLogTable } from "@/components/notifications/NotificationLogTable";
import { Alert } from "@/components/ui/Alert";
import { Pagination } from "@/components/ui/Pagination";
import { ApiError, apiGet } from "@/lib/api";
import { notificationLogListResponse } from "@/lib/schemas/notification-log";

// 通知履歴（F-24）。GET /api/notification-logs?kind=&status=&page= をそのまま表示する。
// 並び（新しい順）・宛先と対象のラベル・絞り込みの値の検証は API が決める

type Param = string | string[] | undefined;

type Props = {
  searchParams: Promise<{
    kind?: Param;
    status?: Param;
    page?: Param;
  }>;
};

/** ?a=1&a=2 のように配列で来たら先頭を使う。値の妥当性は Laravel に任せる */
function first(value: Param): string {
  return (Array.isArray(value) ? value[0] : value) ?? "";
}

/** 空の条件は送らない（API は空・未指定なら絞り込まない契約） */
function toQuery(values: Record<string, string>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(values)) {
    if (value !== "") {
      params.set(key, value);
    }
  }
  return params.toString();
}

/** 絞り込みの値が不正（422）なら一覧の代わりにエラーを返す。それ以外の失敗は投げ直す */
async function fetchLogs(query: string) {
  try {
    return {
      result: await apiGet(
        `/api/notification-logs?${query}`,
        notificationLogListResponse,
      ),
    };
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      return { error: e };
    }
    throw e;
  }
}

export default async function NotificationsPage({ searchParams }: Props) {
  const params = await searchParams;
  const filters: NotificationFilterValues = {
    kind: first(params.kind),
    status: first(params.status),
  };
  const page = first(params.page) || "1";
  const { result, error } = await fetchLogs(toQuery({ ...filters, page }));
  const filtered = filters.kind !== "" || filters.status !== "";

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold whitespace-nowrap">通知履歴</h1>

      <NotificationFilters values={filters} errors={error?.errors} />

      {/* 項目エラーは絞り込みの入力欄の下、項目に紐づかないものは Alert（振り分けは Alert の中） */}
      {error && (
        <Alert
          state={{ ok: false, message: error.message, errors: error.errors }}
        />
      )}

      {result && (
        <>
          <NotificationLogTable
            logs={result.data}
            empty={
              filtered
                ? "条件に合う通知はありません。"
                : "通知の履歴はまだありません。"
            }
          />

          <Pagination
            meta={result.meta}
            basePath="/notifications"
            query={filters}
          />
        </>
      )}
    </div>
  );
}
