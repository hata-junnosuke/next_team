// 状態の value → バッジの色。対応表はここ 1 箇所だけ（docs/01 6.1・7.5）。
// ラベルは API の label をそのまま表示する。ここには色しか置かない。
// 値は docs/01 4.1 の Enum（非同期の状態も含む）

export type Tone = "gray" | "blue" | "green" | "amber" | "red" | "purple";

export const statusTones = {
  // JobPostingStatus
  jobPosting: { open: "green", closed: "gray", cancelled: "red" },
  // ApplicationStatus
  application: {
    applied: "blue",
    confirmed: "green",
    rejected: "gray",
    cancelled: "red",
  },
  // CancelReason
  cancelReason: { company: "amber", worker: "gray" },
  // NotificationKind（P4）
  notificationKind: {
    application_confirmed: "green",
    posting_cancelled: "red",
    shift_reminder: "blue",
    export_ready: "purple",
  },
  // NotificationStatus（P4）
  notificationStatus: { pending: "amber", sent: "green", failed: "red" },
  // ReportExportStatus（P4）
  reportExportStatus: {
    queued: "gray",
    processing: "blue",
    completed: "green",
    failed: "red",
  },
} as const satisfies Record<string, Record<string, Tone>>;

export type StatusKind = keyof typeof statusTones;

const toneClasses: Record<Tone, string> = {
  gray: "bg-gray-100 text-gray-700 ring-gray-300",
  blue: "bg-blue-50 text-blue-700 ring-blue-300",
  green: "bg-green-50 text-green-700 ring-green-300",
  amber: "bg-amber-50 text-amber-800 ring-amber-300",
  red: "bg-red-50 text-red-700 ring-red-300",
  purple: "bg-purple-50 text-purple-700 ring-purple-300",
};

/** 未知の value（API に Enum が増えた）は灰色で出す。ラベルは API が返すので表示は崩れない */
export function statusClass(kind: StatusKind, value: string): string {
  const tones: Record<string, Tone> = statusTones[kind];
  return toneClasses[tones[value] ?? "gray"];
}

// ReportExportStatus のうち「生成中」にあたる value（docs/01 4.1・6.9）。
// 進捗を出すか・「更新すると最新になります」の注記を出すかの表示の判断にだけ使う（依頼を受け付けるかは API の 422）
const inProgressExportStatuses: readonly string[] = ["queued", "processing"];

/** 出力の状態の value が生成中（queued / processing）か */
export function isInProgressExportStatus(value: string): boolean {
  return inProgressExportStatuses.includes(value);
}

/** 出力の状態の value が失敗（failed）か。「この月をもう一度依頼」のリンクを出す表示の判断にだけ使う */
export function isFailedExportStatus(value: string): boolean {
  return value === "failed";
}
