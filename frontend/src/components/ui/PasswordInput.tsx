import { useState, type InputHTMLAttributes } from "react";
import { Eye, EyeOff } from "lucide-react";

export default function PasswordInput(props: Omit<InputHTMLAttributes<HTMLInputElement>, "type">) {
  const [visible, setVisible] = useState(false);
  return (
    <div className="relative">
      <input {...props} type={visible ? "text" : "password"} className={`${props.className || ""} pr-12`} />
      <button type="button" onClick={() => setVisible((value) => !value)} className="absolute inset-y-0 right-0 grid w-11 place-items-center text-[var(--color-text-secondary)] hover:text-[var(--color-text)]" aria-label={visible ? "Hide password" : "Show password"} aria-pressed={visible}>
        {visible ? <EyeOff size={17} /> : <Eye size={17} />}
      </button>
    </div>
  );
}
