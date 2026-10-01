import Link from "next/link";
import { DeleteLocationButton } from "@/components/locations/DeleteLocationButton";
import { Pagination } from "@/components/ui/Pagination";
import { Table, Td } from "@/components/ui/Table";
import { deleteLocation } from "@/lib/actions/location";
import { apiGet } from "@/lib/api";
import { locationListResponse } from "@/lib/schemas/location";

// 拠点一覧（F-01）。GET /api/locations?page= をそのまま表示する

type Props = {
  searchParams: Promise<{ page?: string | string[] }>;
};

const linkClass = "whitespace-nowrap text-blue-700 hover:underline";

export default async function LocationsPage({ searchParams }: Props) {
  const { page } = await searchParams;
  // ?page=1&page=2 のように配列で来たら先頭を使う。値の妥当性は Laravel に任せる
  const current = (Array.isArray(page) ? page[0] : page) ?? "1";
  const { data: locations, meta } = await apiGet(
    `/api/locations?page=${encodeURIComponent(current)}`,
    locationListResponse,
  );

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-4">
        <h1 className="text-xl font-semibold whitespace-nowrap">拠点</h1>
        <Link
          href="/locations/new"
          className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium whitespace-nowrap text-white hover:bg-blue-700"
        >
          拠点を登録
        </Link>
      </div>

      <Table
        head={["名称", "住所", "募集数", ""]}
        empty="拠点がまだ登録されていません。"
      >
        {locations.map((location) => (
          <tr key={location.id} className="align-top">
            <Td>{location.name}</Td>
            <Td>{location.address ?? "—"}</Td>
            <Td align="right">{location.job_postings_count}</Td>
            <Td>
              <div className="flex items-start gap-3">
                <Link
                  href={`/locations/${location.id}/edit`}
                  className={`${linkClass} py-2`}
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

      <Pagination meta={meta} basePath="/locations" />
    </div>
  );
}
