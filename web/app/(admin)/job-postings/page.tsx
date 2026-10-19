import Link from "next/link";
import { Fragment } from "react";
import { fetchLocationOptions } from "@/components/job-postings/location-options";
import {
  JobPostingFilters,
  type JobPostingFilterValues,
} from "@/components/job-postings/JobPostingFilters";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { buttonClassName } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { emptyText, Pagination } from "@/components/ui/Pagination";
import { Table, Td } from "@/components/ui/Table";
import { ApiError, apiGet } from "@/lib/api";
import { date, yen } from "@/lib/format";
import { jobPostingListResponse } from "@/lib/schemas/job-posting";

// 募集一覧（F-06）。GET /api/job-postings?date_from&date_to&location_id&status&page。
// 並び（勤務日の昇順）と件数（応募数は却下・キャンセルを含む全件、確定数）は API が決める

type Param = string | string[] | undefined;

type Props = {
  searchParams: Promise<{
    date_from?: Param;
    date_to?: Param;
    location_id?: Param;
    status?: Param;
    page?: Param;
  }>;
};

// 表の見出し。勤務日は区切り行、拠点は職種名の 2 段目に出す（列数は区切り行の colSpan にも使う）
const HEAD = [
  "時間",
  "職種名",
  "状態",
  "応募数",
  "確定 / 定員",
  "時給",
  "交通費",
  "",
];

/** ?a=1&a=2 のように配列で来たら先頭を使う。値の妥当性は Laravel に任せる */
function first(value: Param): string {
  return (Array.isArray(value) ? value[0] : value) ?? "";
}

/** 空の条件は送らない（未指定と同じ扱いにする） */
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
async function fetchPostings(query: string) {
  try {
    return {
      result: await apiGet(
        `/api/job-postings?${query}`,
        jobPostingListResponse,
      ),
    };
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) {
      return { error: e };
    }
    throw e;
  }
}

export default async function JobPostingsPage({ searchParams }: Props) {
  const params = await searchParams;
  const filters: JobPostingFilterValues = {
    date_from: first(params.date_from),
    date_to: first(params.date_to),
    location_id: first(params.location_id),
    status: first(params.status),
  };
  const page = first(params.page) || "1";

  const [locations, { result, error }] = await Promise.all([
    fetchLocationOptions(),
    fetchPostings(toQuery({ ...filters, page })),
  ]);

  return (
    <div className="space-y-6">
      <PageHeader
        title="募集"
        actions={
          <Link href="/job-postings/new" className={buttonClassName("primary")}>
            募集を登録
          </Link>
        }
      />

      <JobPostingFilters
        values={filters}
        locations={locations}
        errors={error?.errors}
      />

      {/* 項目エラーは絞り込みの入力欄の下、項目に紐づかないものは Alert（振り分けは Alert の中） */}
      {error && (
        <Alert
          state={{ ok: false, message: error.message, errors: error.errors }}
        />
      )}

      {result && (
        <>
          <Table
            head={HEAD}
            empty={emptyText(
              result.meta,
              result.data.length,
              "条件に合う募集はありません。",
            )}
          >
            {result.data.map((posting, i) => (
              <Fragment key={posting.id}>
                {/* 勤務日の区切り行。API の並び（勤務日の昇順）のまま、前の行と勤務日が変わる所に入れるだけ */}
                {posting.work_date !== result.data[i - 1]?.work_date && (
                  // 地は canvas（データ行のホバーの surface-muted と区別する）。Table のホバーの指定より強くするため ! を付ける
                  <tr className="bg-canvas!">
                    <td
                      colSpan={HEAD.length}
                      className="text-ink px-4 py-2 text-left text-xs font-semibold whitespace-nowrap"
                    >
                      {date(posting.work_date)}
                    </td>
                  </tr>
                )}
                <tr>
                  <Td>
                    {/* 開始は大きく、終了は控えめに（発車標のように時刻を遠目で読めるようにする） */}
                    <span className="font-num inline-flex items-baseline gap-1">
                      <span className="text-2xl leading-none font-semibold">
                        {posting.start_time}
                      </span>
                      <span className="text-ink-muted text-base leading-none">
                        〜{posting.end_time}
                      </span>
                    </span>
                  </Td>
                  <Td>
                    <Link
                      href={`/job-postings/${posting.id}`}
                      className="text-ink font-semibold whitespace-nowrap underline-offset-2 hover:underline"
                    >
                      {posting.job_title}
                    </Link>
                    <div className="text-ink-muted mt-0.5 text-xs whitespace-nowrap">
                      {posting.location.name}
                    </div>
                  </Td>
                  <Td>
                    <Badge kind="jobPosting" status={posting.status} />
                  </Td>
                  <Td align="right">
                    <span className="font-num text-base">
                      {posting.applications_count}
                    </span>
                  </Td>
                  <Td align="right">
                    <span className="font-num text-base">
                      {posting.confirmed_count} / {posting.capacity}
                    </span>
                  </Td>
                  <Td align="right">
                    <span className="font-num text-base">
                      {yen(posting.hourly_wage)}
                    </span>
                  </Td>
                  <Td align="right">
                    <span className="font-num text-base">
                      {yen(posting.transport_allowance)}
                    </span>
                  </Td>
                  <Td>
                    <div className="flex gap-2">
                      <Link
                        href={`/job-postings/${posting.id}`}
                        className={buttonClassName("secondary", "sm")}
                      >
                        詳細
                      </Link>
                      <Link
                        href={`/job-postings/${posting.id}/edit`}
                        className={buttonClassName("secondary", "sm")}
                      >
                        {/* 中止した募集は編集できないが複製はできる（編集画面に複製のフォームがある） */}
                        {posting.can.edit ? "編集・複製" : "複製"}
                      </Link>
                    </div>
                  </Td>
                </tr>
              </Fragment>
            ))}
          </Table>

          <Pagination
            meta={result.meta}
            basePath="/job-postings"
            query={filters}
            itemCount={result.data.length}
          />
        </>
      )}
    </div>
  );
}
