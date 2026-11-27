import { Link } from "react-router-dom";
import CustomerDepositPanel from "@/components/account/CustomerDepositPanel";

export default function CustomerDepositsPage() {
  return <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6">
    <Link to="/account" className="text-sm font-semibold text-[var(--color-accent)] no-underline">← Back to account</Link>
    <header className="mt-3 mb-6">
      <h1 className="text-2xl font-bold">Deposits &amp; advances</h1>
      <p className="mt-1 text-sm text-[var(--color-text-secondary)]">
        Start a deposit for yourself or an organisation you manage finances for, and follow every payment to its verified status.
      </p>
    </header>
    <CustomerDepositPanel />
  </div>;
}
