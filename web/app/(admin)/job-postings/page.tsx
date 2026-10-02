import Link from "next/link";
import { fetchLocationOptions } from "@/components/job-postings/location-options";
import {
  JobPostingFilters,
  type JobPostingFilterValues,
} from "@/components/job-postings/JobPostingFilters";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
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

const linkClass = "whitespace-nowrap text-blue-700 hover:underline";

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
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-4">
        <h1 className="text-xl font-semibold whitespace-nowrap">募集</h1>
        <Link
          href="/job-postings/new"
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium whitespace-nowrap text-white hover:bg-blue-700"
        >
          募集を登録
        </Link>
      </div>

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
            head={[
              "勤務日",
              "時間",
              "拠点",
              "職種名",
              "状態",
              "応募数",
              "確定 / 定員",
              "時給",
              "交通費",
              "",
            ]}
            empty={emptyText(
              result.meta,
              result.data.length,
              "条件に合う募集はありません。",
            )}
          >
            {result.data.map((posting) => (
              <tr key={posting.id}>
                <Td>{date(posting.work_date)}</Td>
                <Td>
                  {posting.start_time}〜{posting.end_time}
                </Td>
                <Td>{posting.location.name}</Td>
                <Td>
                  <Link
                    href={`/job-postings/${posting.id}`}
                    className={linkClass}
                  >
                    {posting.job_title}
                  </Link>
                </Td>
                <Td>
                  <Badge kind="jobPosting" status={posting.status} />
                </Td>
                <Td align="right">{posting.applications_count}</Td>
                <Td align="right">
                  {posting.confirmed_count} / {posting.capacity}
                </Td>
                <Td align="right">{yen(posting.hourly_wage)}</Td>
                <Td align="right">{yen(posting.transport_allowance)}</Td>
                <Td>
                  <div className="flex gap-3">
                    <Link
                      href={`/job-postings/${posting.id}`}
                      className={linkClass}
                    >
                      詳細
                    </Link>
                    <Link
                      href={`/job-postings/${posting.id}/edit`}
                      className={linkClass}
                    >
                      {/* 中止した募集は編集できないが複製はできる（編集画面に複製のフォームがある） */}
                      {posting.can.edit ? "編集・複製" : "複製"}
                    </Link>
                  </div>
                </Td>
              </tr>
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
