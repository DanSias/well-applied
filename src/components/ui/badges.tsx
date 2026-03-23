export function ParseStatusBadge({ status }: { status: string }) {
  const colours: Record<string, string> = {
    PENDING: "bg-zinc-100 text-zinc-500",
    PROCESSING: "bg-blue-50 text-blue-600",
    COMPLETED: "bg-green-50 text-green-700",
    FAILED: "bg-red-50 text-red-600",
    NEEDS_REVIEW: "bg-amber-50 text-amber-600",
  };
  return (
    <span
      className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${colours[status] ?? "bg-zinc-100 text-zinc-500"}`}
    >
      {status.charAt(0) + status.slice(1).toLowerCase().replace("_", " ")}
    </span>
  );
}

export function ApplicationStatusBadge({ status }: { status: string }) {
  const colours: Record<string, string> = {
    SAVED: "bg-zinc-100 text-zinc-500",
    APPLIED: "bg-blue-50 text-blue-600",
    INTERVIEWING: "bg-purple-50 text-purple-700",
    OFFER: "bg-green-50 text-green-700",
    REJECTED: "bg-red-50 text-red-600",
    WITHDRAWN: "bg-zinc-100 text-zinc-400",
  };
  return (
    <span
      className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${colours[status] ?? "bg-zinc-100 text-zinc-500"}`}
    >
      {status.charAt(0) + status.slice(1).toLowerCase()}
    </span>
  );
}
