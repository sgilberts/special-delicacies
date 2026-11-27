/**
 * Detects the backend's both-contact verification gate (HTTP 403 with
 * code=customer_verification_required) so customer screens can explain the
 * restriction instead of showing a generic failure.
 */
export function verificationGate(cause: unknown): { missing: string[] } | null {
  const response = (cause as { response?: { status?: number; data?: { code?: string; missing?: string[] } } }).response;
  if (response?.status === 403 && response.data?.code === "customer_verification_required") {
    return { missing: response.data.missing ?? [] };
  }
  return null;
}
