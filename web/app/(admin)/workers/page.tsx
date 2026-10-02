import Link from "next/link";
import { ToggleWorkerActiveButton } from "@/components/workers/ToggleWorkerActiveButton";
import { WorkerSearchForm } from "@/components/workers/WorkerSearchForm";
import { Alert } from "@/components/ui/Alert";
import { emptyText, Pagination } from "@/components/ui/Pagination";
import { Table, Td } from "@/components/ui/Table";
import { setWorkerActive } from "@/lib/actions/worker";
import { ApiError, apiGet } from "@/lib/api";
import { workerListResponse } from "@/lib/schemas/worker";

// ワーカー一覧（F-02〜F-04）。GET /api/workers?q=&is_active=&page= をそのまま表示する。
// 絞り込みの解釈（空白の除去・部分一致・is_active の値の妥当性）は API が行う

type Props = {
  searchParams: Promise<{
    q?: string | string[];
    is_active?: string | string[];
    page?: string | string[];
  }>;
};

const linkClass = "whitespace-nowrap text-blue-700 hover:underline";

/** ?q=a&q=b のように配列で来たら先頭を使う */
function first(value: string | string[] | undefined): string {
  return (Array.isArray(value) ? value[0] : value) ?? "";
}

export default async function WorkersPage({ searchParams }: Props) {
  const params = await searchParams;
  const q = first(params.q);
  const isActive = first(params.is_active);
  const page = first(params.page) || "1";

  const query = new URLSearchParams({ page });
  // 空は送らない（API は空・未指定なら絞り込まない契約。送っても同じだが URL を短くする）
  if (q !== "") query.set("q", q);
  if (isActive !== "") query.set("is_active", isActive);

  let result;
  try {
    result = await apiGet(
      `/api/workers?${query.toString()}`,
      workerListResponse,
    );
  } catch (e) {
    // URL を手で書き換えて is_active に不正な値を入れると API が 422 を返す。
    // 判定は API に任せ、画面全体をエラーにせず message を出す
    if (!(e instanceof ApiError && e.status === 422)) {
      throw e;
    }
    result = { error: e.message };
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-4">
        <h1 className="text-xl font-semibold whitespace-nowrap">ワーカー</h1>
        <Link
          href="/workers/new"
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium whitespace-nowrap text-white hover:bg-blue-700"
        >
          ワーカーを登録
        </Link>
      </div>

      <WorkerSearchForm q={q} isActive={isActive} />

      {"error" in result ? (
        <Alert state={{ ok: false, message: result.error }} />
      ) : (
        <>
          <Table
            head={["氏名", "メールアドレス", "電話番号", "状態", ""]}
            empty={emptyText(
              result.meta,
              result.data.length,
              q !== "" || isActive !== ""
                ? "条件に合うワーカーがいません。"
                : "ワーカーがまだ登録されていません。",
            )}
          >
            {result.data.map((worker) => (
              <tr key={worker.id} className="align-top">
                <Td>{worker.name}</Td>
                <Td>{worker.email}</Td>
                <Td>{worker.phone ?? "—"}</Td>
                <Td>
                  <span className="inline-block py-2">
                    {worker.is_active ? "有効" : "無効"}
                  </span>
                </Td>
                <Td>
                  <div className="flex items-start gap-3">
                    <Link
                      href={`/workers/${worker.id}/edit`}
                      className={`${linkClass} py-2`}
                    >
                      編集
                    </Link>
                    {/* bind は Server 側で作り、id と切り替え先は API が返した値から決める */}
                    <ToggleWorkerActiveButton
                      action={setWorkerActive.bind(
                        null,
                        worker.id,
                        !worker.is_active,
                      )}
                      isActive={worker.is_active}
                    />
                  </div>
                </Td>
              </tr>
            ))}
          </Table>

          <Pagination
            meta={result.meta}
            basePath="/workers"
            query={{ q, is_active: isActive }}
            itemCount={result.data.length}
          />
        </>
      )}
    </div>
  );
}
