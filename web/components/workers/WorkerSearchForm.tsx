import Form from "next/form";
import Link from "next/link";
import { Button, buttonClassName } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Field } from "@/components/ui/Field";

// 一覧の絞り込み（F-04）。GET で /workers?q=&is_active= に遷移するだけ（next/form）。
// 値の解釈（前後の空白・部分一致・is_active の値）は API が行う。ページは 1 に戻る（page を送らない）

type WorkerSearchFormProps = {
  q: string;
  isActive: string;
};

const activeOptions = [
  { value: "1", label: "有効" },
  { value: "0", label: "無効" },
];

export function WorkerSearchForm({ q, isActive }: WorkerSearchFormProps) {
  return (
    <Card>
      {/* key: 「条件を消す」などのリンクで URL が変わったとき、入力欄の defaultValue を読み直させる */}
      <Form
        key={`${q}\u0000${isActive}`}
        action="/workers"
        className="flex flex-wrap items-end gap-3"
      >
        <div className="w-64">
          <Field
            label="氏名"
            name="q"
            defaultValue={q}
            placeholder="部分一致"
          />
        </div>
        <div className="w-40">
          <Field
            label="状態"
            name="is_active"
            type="select"
            emptyOption="全て"
            options={activeOptions}
            defaultValue={isActive}
          />
        </div>
        <Button>検索</Button>
        <Link href="/workers" className={buttonClassName("secondary")}>
          条件を消す
        </Link>
      </Form>
    </Card>
  );
}
