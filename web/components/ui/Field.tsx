// ラベル + 入力 + その項目のエラー（422 の errors[name]）。
// 検証は Laravel が正。ここでは required 程度しか付けない

type Option = { value: string | number; label: string };

type FieldProps = {
  label: string;
  name: string;
  /**
   * 入力欄の id（エラーの id は `${id}-error`）。既定は `field-${name}`。
   * 同じ name の Field が 1 ページに複数並ぶとき（例: 応募ごとの勤怠フォーム）は
   * `field-attendance-${application.id}-clock_in` のように重ならない id を渡す
   */
  id?: string;
  type?:
    | "text"
    | "email"
    | "password"
    | "number"
    | "date"
    | "time"
    | "select"
    | "textarea";
  defaultValue?: string | number | null;
  errors?: string[];
  required?: boolean;
  placeholder?: string;
  autoComplete?: string;
  /** type="select" のときの選択肢 */
  options?: Option[];
  /** type="select" で未選択を許すときの文言 */
  emptyOption?: string;
};

const inputClass =
  "block w-full rounded-md border px-3 py-2 text-sm shadow-xs focus:outline-none focus:ring-2";

export function Field({
  label,
  name,
  id: idProp,
  type = "text",
  defaultValue,
  errors,
  required,
  placeholder,
  autoComplete,
  options = [],
  emptyOption,
}: FieldProps) {
  const id = idProp ?? `field-${name}`;
  const hasError = (errors?.length ?? 0) > 0;
  const stateClass = hasError
    ? "border-red-400 focus:ring-red-200"
    : "border-gray-300 focus:ring-blue-200";
  const common = {
    id,
    name,
    required,
    defaultValue: defaultValue ?? undefined,
    "aria-invalid": hasError || undefined,
    "aria-describedby": hasError ? `${id}-error` : undefined,
    className: `${inputClass} ${stateClass}`,
  };

  return (
    <div className="space-y-1">
      <label
        htmlFor={id}
        className="block text-sm font-medium whitespace-nowrap text-gray-700"
      >
        {label}
        {required && <span className="ml-1 text-red-600">*</span>}
      </label>
      {type === "select" ? (
        <select {...common}>
          {emptyOption !== undefined && <option value="">{emptyOption}</option>}
          {options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      ) : type === "textarea" ? (
        <textarea {...common} rows={3} placeholder={placeholder} />
      ) : (
        <input
          {...common}
          type={type}
          placeholder={placeholder}
          autoComplete={autoComplete}
        />
      )}
      {hasError && (
        <ul id={`${id}-error`} className="space-y-0.5 text-sm text-red-600">
          {errors?.map((e) => (
            <li key={e}>{e}</li>
          ))}
        </ul>
      )}
    </div>
  );
}
