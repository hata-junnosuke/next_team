import Form from "next/form";
import Link from "next/link";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Field } from "@/components/ui/Field";
import type { statusTones } from "@/lib/status";

// 通知履歴の絞り込み（F-24）。GET のフォームで /notifications?kind=&status= に遷移するだけ（next/form）。
// 値の検証（Enum にない値）は API の 422 で、そのエラーを errors で受けて出す。ページは 1 に戻る（page を送らない）。
// kind / status 以外の項目エラー（URL を手で書き換えた page・per_page など。入力欄がない）は
// フォームの下にまとめて出す（docs/01 の表示の規約の例外。複製フォームと同じ形）

/** 一覧ページが URL から受け取った絞り込みの値（未指定は空文字） */
export type NotificationFilterValues = {
  kind: string;
  status: string;
};

type KindValue = keyof (typeof statusTones)["notificationKind"];
type StatusValue = keyof (typeof statusTones)["notificationStatus"];

// 選択肢。API に Enum の一覧を返す口がないため、docs/01 の Enum の NotificationKind / NotificationStatus の
// value → label をここに置く（docs/01 の表示の規約の例外。表のバッジは API の label をそのまま出す）
const kindOptions: { value: KindValue; label: string }[] = [
  { value: "application_confirmed", label: "確定通知" },
  { value: "posting_cancelled", label: "中止通知" },
  { value: "shift_reminder", label: "前日リマインド" },
  { value: "export_ready", label: "出力完了" },
];

const statusOptions: { value: StatusValue; label: string }[] = [
  { value: "pending", label: "送信待ち" },
  { value: "sent", label: "送信済み" },
  { value: "failed", label: "失敗" },
];

/** 入力欄のある項目。それ以外の項目エラーはまとめて出す */
const fieldNames: readonly string[] = ["kind", "status"];

type NotificationFiltersProps = {
  values: NotificationFilterValues;
  /** 絞り込みの値が不正なときの 422 の errors */
  errors?: Record<string, string[]>;
};

export function NotificationFilters({
  values,
  errors,
}: NotificationFiltersProps) {
  const otherErrors = Object.entries(errors ?? {})
    .filter(([name]) => !fieldNames.includes(name))
    .flatMap(([, messages]) => messages);

  return (
    <Card>
      {/* 条件が変わったら入力欄を作り直す（defaultValue は初回しか効かないため。「条件を消す」で空に戻す） */}
      <Form
        key={JSON.stringify(values)}
        action="/notifications"
        className="flex flex-wrap items-end gap-4"
      >
        <div className="w-48">
          <Field
            label="種別"
            name="kind"
            type="select"
            options={kindOptions}
            emptyOption="すべて"
            defaultValue={values.kind}
            errors={errors?.kind}
          />
        </div>
        <div className="w-40">
          <Field
            label="状態"
            name="status"
            type="select"
            options={statusOptions}
            emptyOption="すべて"
            defaultValue={values.status}
            errors={errors?.status}
          />
        </div>
        <div className="flex items-center gap-3">
          <Button variant="secondary">絞り込む</Button>
          <Link
            href="/notifications"
            className="text-ink-muted hover:text-ink text-sm whitespace-nowrap transition-colors"
          >
            条件を消す
          </Link>
        </div>
        {otherErrors.length > 0 && (
          <ul role="alert" className="text-danger w-full space-y-0.5 text-sm">
            {otherErrors.map((message) => (
              <li key={message}>{message}</li>
            ))}
          </ul>
        )}
      </Form>
    </Card>
  );
}
