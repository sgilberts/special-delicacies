import { Check, CheckCheck } from "lucide-react";

interface MessageReceiptProps {
  deliveredAt: string | null;
  readAt: string | null;
}

export default function MessageReceipt({ deliveredAt, readAt }: MessageReceiptProps) {
  const label = readAt ? "Read" : deliveredAt ? "Delivered" : "Sent";
  return <span role="img" aria-label={label} title={label} className={`inline-flex shrink-0 align-middle ${readAt ? "text-[var(--color-receipt-read)]" : "text-[var(--color-text-secondary)]"}`}>
    {deliveredAt ? <CheckCheck size={14} strokeWidth={2.4} aria-hidden="true" /> : <Check size={13} strokeWidth={2.4} aria-hidden="true" />}
  </span>;
}
