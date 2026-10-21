import Link from "next/link";
import { DeleteLocationButton } from "@/components/locations/DeleteLocationButton";
import { buttonClassName } from "@/components/ui/Button";
import { PageHeader } from "@/components/ui/PageHeader";
import { emptyText, Pagination } from "@/components/ui/Pagination";
import { Table, Td } from "@/components/ui/Table";
import { deleteLocation } from "@/lib/actions/location";
import { apiGet } from "@/lib/api";
import { locationListResponse } from "@/lib/schemas/location";

// 拠点一覧（F-01）。GET /api/locations?page= をそのまま表示する

type Props = {
  searchParams: Promise<{ page?: string | string[] }>;
};

export default async function LocationsPage({ searchParams }: Props) {
  const { page } = await searchParams;
  // ?page=1&page=2 のように配列で来たら先頭を使う。値の妥当性は Laravel に任せる
  const current = (Array.isArray(page) ? page[0] : page) ?? "1";
  const { data: locations, meta } = await apiGet(
    `/api/locations?page=${encodeURIComponent(current)}`,
    locationListResponse,
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title="拠点"
        actions={
          <Link href="/locations/new" className={buttonClassName("primary")}>
            拠点を登録
          </Link>
        }
      />

      <Table
        head={["名称", "住所", "募集数", ""]}
        empty={emptyText(
          meta,
          locations.length,
          "拠点がまだ登録されていません。",
        )}
      >
        {locations.map((location) => (
          <tr key={location.id} className="align-top">
            <Td>{location.name}</Td>
            <Td>{location.address ?? "—"}</Td>
            <Td align="right">{location.job_postings_count}</Td>
            <Td>
              <div className="flex items-start gap-2">
                <Link
                  href={`/locations/${location.id}/edit`}
                  className={buttonClassName("secondary", "sm")}
                >
                  編集
                </Link>
                {/* 削除できるかは API の deletable を見て非活性にする（web で判定しない）。
                    bind は Server 側で作り、id は API が返した値を使う */}
                <DeleteLocationButton
                  action={deleteLocation.bind(null, location.id)}
                  name={location.name}
                  deletable={location.deletable}
                />
              </div>
            </Td>
          </tr>
        ))}
      </Table>

      <Pagination
        meta={meta}
        basePath="/locations"
        itemCount={locations.length}
      />
    </div>
  );
}
