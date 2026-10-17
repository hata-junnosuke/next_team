import Form from "next/form";
import Link from "next/link";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import type { statusTones } from "@/lib/status";

// 募集一覧の絞り込み（F-06）。GET のフォームで URL の検索条件にするだけ。
// 値の検証（日付の形式・から <= まで・状態の値）は API の 422 で、そのエラーを errors で受けて出す

/** 一覧ページが URL から受け取った絞り込みの値（未指定は空文字） */
export type JobPostingFilterValues = {
  date_from: string;
  date_to: string;
  location_id: string;
  status: string;
};

type StatusValue = keyof (typeof statusTones)["jobPosting"];

// 状態の選択肢。API に Enum の一覧を返す口がないため、docs/01 の Enum の JobPostingStatus の
// value → label をここに置く（表のバッジは API の label をそのまま出す）
const statusOptions: { value: StatusValue; label: string }[] = [
  { value: "open", label: "募集中" },
  { value: "closed", label: "締切" },
  { value: "cancelled", label: "中止" },
];

type JobPostingFiltersProps = {
  values: JobPostingFilterValues;
  locations: { value: number; label: string }[];
  /** 絞り込みの値が不正なときの 422 の errors */
  errors?: Record<string, string[]>;
};

export function JobPostingFilters({
  values,
  locations,
  errors,
}: JobPostingFiltersProps) {
  return (
    // 条件が変わったら入力欄を作り直す（defaultValue は初回しか効かないため。「条件を消す」で空に戻す）
    <Form
      key={JSON.stringify(values)}
      action="/job-postings"
      className="grid grid-cols-2 items-end gap-4 rounded-md border border-gray-200 bg-white p-4 md:grid-cols-5"
    >
      <Field
        label="勤務日（から）"
        name="date_from"
        type="date"
        defaultValue={values.date_from}
        errors={errors?.date_from}
      />
      <Field
        label="勤務日（まで）"
        name="date_to"
        type="date"
        defaultValue={values.date_to}
        errors={errors?.date_to}
      />
      <Field
        label="拠点"
        name="location_id"
        type="select"
        options={locations}
        emptyOption="すべて"
        defaultValue={values.location_id}
        errors={errors?.location_id}
      />
      <Field
        label="状態"
        name="status"
        type="select"
        options={statusOptions}
        emptyOption="すべて"
        defaultValue={values.status}
        errors={errors?.status}
      />
      <div className="flex items-center gap-3">
        <Button variant="secondary">絞り込む</Button>
        <Link
          href="/job-postings"
          className="text-sm whitespace-nowrap text-gray-600 hover:text-gray-900"
        >
          条件を消す
        </Link>
      </div>
    </Form>
  );
}
