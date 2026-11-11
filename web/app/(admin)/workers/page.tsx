import Link from "next/link";
import { ActiveBadge } from "@/components/workers/ActiveBadge";
import { InvitationStatus } from "@/components/workers/InvitationStatus";
import { ResendInvitationButton } from "@/components/workers/ResendInvitationButton";
import { ToggleWorkerActiveButton } from "@/components/workers/ToggleWorkerActiveButton";
import { WorkerSearchForm } from "@/components/workers/WorkerSearchForm";
import { Alert } from "@/components/ui/Alert";
import { buttonClassName } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { emptyText, Pagination } from "@/components/ui/Pagination";
import { Table, Td } from "@/components/ui/Table";
import { resendWorkerInvitation, setWorkerActive } from "@/lib/actions/worker";
import { ApiError, apiGet } from "@/lib/api";
import { userResponse } from "@/lib/schemas/user";
import { workerListResponse } from "@/lib/schemas/worker";

// ワーカー一覧（F-02〜F-04）。GET /api/workers?q=&is_active=&page= をそのまま表示する。
// 絞り込みの解釈（空白の除去・部分一致・is_active の値の妥当性）は API が行う。
// 一覧の上に企業コード（GET /api/user の company.code）を出す。管理者がワーカーに伝えるため（issue #38 の Q2）

type Props = {
  searchParams: Promise<{
    q?: string | string[];
    is_active?: string | string[];
    page?: string | string[];
  }>;
};

/** ?q=a&q=b のように配列で来たら先頭を使う */
function first(value: string | string[] | undefined): string {
  return (Array.isArray(value) ? value[0] : value) ?? "";
}

/**
 * ワーカー一覧を読む。URL を手で書き換えて is_active に不正な値を入れると API が 422 を返す。
 * 判定は API に任せ、画面全体をエラーにせず message を出す
 */
async function fetchWorkers(query: URLSearchParams) {
  try {
    return await apiGet(`/api/workers?${query.toString()}`, workerListResponse);
  } catch (e) {
    if (!(e instanceof ApiError && e.status === 422)) {
      throw e;
    }
    return { error: e.message };
  }
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

  const [{ data: user }, result] = await Promise.all([
    apiGet("/api/user", userResponse),
    fetchWorkers(query),
  ]);

  return (
    <div className="space-y-6">
      <PageHeader
        title="ワーカー"
        actions={
          <Link href="/workers/new" className={buttonClassName("primary")}>
            ワーカーを登録
          </Link>
        }
        description={
          <>
            <span className="block whitespace-nowrap">
              企業コード:{" "}
              <span className="text-ink font-mono text-base font-semibold tracking-wider select-all">
                {user.company.code}
              </span>
            </span>
            <span className="block">
              ワーカーはアプリのログインでこのコードを入力します。
            </span>
          </>
        }
      />

      <WorkerSearchForm q={q} isActive={isActive} />

      {"error" in result ? (
        <Alert state={{ ok: false, message: result.error }} />
      ) : (
        <>
          <Table
            head={["氏名", "メールアドレス", "電話番号", "状態", "招待", ""]}
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
                  {/* 行内ボタン（h-8）と高さをそろえる */}
                  <span className="inline-flex h-8 items-center">
                    <ActiveBadge isActive={worker.is_active} />
                  </span>
                </Td>
                <Td>
                  <span className="inline-flex h-8 items-center">
                    <InvitationStatus worker={worker} />
                  </span>
                </Td>
                <Td>
                  <div className="flex items-start gap-2">
                    <Link
                      href={`/workers/${worker.id}/edit`}
                      className={buttonClassName("secondary", "sm")}
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
                    {/* 出すかどうかは API の can.invite だけで決める（送れるかの最終的な判定は API の 422） */}
                    {worker.can.invite && (
                      <ResendInvitationButton
                        action={resendWorkerInvitation.bind(null, worker.id)}
                      />
                    )}
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
