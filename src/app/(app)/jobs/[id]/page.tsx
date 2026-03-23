import { notFound } from "next/navigation";
import Link from "next/link";
import { prisma } from "@/lib/prisma";
import type { ParsedJob } from "@/lib/mock-data";
import {
  ParseStatusBadge,
  ApplicationStatusBadge,
} from "@/components/ui/badges";

export default async function JobDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const job = await prisma.job.findUnique({
    where: { id },
    include: { application: true },
  });

  if (!job) notFound();

  const parsed = job.parsedStructured as ParsedJob | null;

  return (
    <div className="flex flex-col gap-6">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <Link
            href="/jobs"
            className="mb-1 inline-block text-xs text-zinc-400 hover:text-zinc-600"
          >
            ← Jobs
          </Link>
          <h1 className="text-2xl font-semibold text-zinc-900">{job.title}</h1>
          <p className="mt-0.5 text-sm text-zinc-500">{job.company}</p>
        </div>
        <div className="flex flex-col items-end gap-1">
          <ParseStatusBadge status={job.parseStatus} />
          {job.application && (
            <ApplicationStatusBadge status={job.application.status} />
          )}
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {/* Raw description */}
        <section className="rounded-lg border border-zinc-200 bg-white p-5">
          <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">
            Raw Description
          </h2>
          <p className="whitespace-pre-wrap text-sm leading-relaxed text-zinc-700">
            {job.rawDescription}
          </p>
        </section>

        {/* Parsed structured data */}
        <div className="flex flex-col gap-4">
          {parsed ? (
            <>
              <section className="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                  Summary
                </h2>
                <p className="text-sm leading-relaxed text-zinc-700">
                  {parsed.summary}
                </p>
              </section>

              <section className="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                  Responsibilities
                </h2>
                <ul className="flex flex-col gap-1">
                  {parsed.responsibilities.map((r, i) => (
                    <li key={i} className="flex gap-2 text-sm text-zinc-700">
                      <span className="mt-0.5 shrink-0 text-zinc-300">•</span>
                      {r}
                    </li>
                  ))}
                </ul>
              </section>

              <section className="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                  Required Qualifications
                </h2>
                <ul className="flex flex-col gap-1">
                  {parsed.requiredQualifications.map((q, i) => (
                    <li key={i} className="flex gap-2 text-sm text-zinc-700">
                      <span className="mt-0.5 shrink-0 text-zinc-300">•</span>
                      {q}
                    </li>
                  ))}
                </ul>
              </section>

              <section className="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                  Skills
                </h2>
                <div className="flex flex-wrap gap-2">
                  {parsed.skills.map((skill) => (
                    <span
                      key={skill}
                      className="rounded bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700"
                    >
                      {skill}
                    </span>
                  ))}
                </div>
              </section>
            </>
          ) : (
            <div className="rounded-lg border border-dashed border-zinc-200 py-12 text-center">
              <p className="text-sm text-zinc-400">No parsed data available.</p>
            </div>
          )}
        </div>
      </div>

      {/* Metadata footer */}
      <p className="text-xs text-zinc-400">
        Added {new Date(job.createdAt).toLocaleString()}
      </p>
    </div>
  );
}

