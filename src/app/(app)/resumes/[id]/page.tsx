import { notFound } from "next/navigation";
import Link from "next/link";
import { prisma } from "@/lib/prisma";
import { ParseStatusBadge } from "@/components/ui/badges";
import type { ParsedResume } from "@/lib/mock-data";

export default async function ResumeDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const resume = await prisma.resume.findUnique({
    where: { id },
    include: { versions: { orderBy: { versionNumber: "asc" } } },
  });

  if (!resume) notFound();

  const parsed = resume.structuredData as ParsedResume | null;
  const latestVersion = resume.versions.at(-1);
  const latestVersionData = latestVersion?.structuredData as ParsedResume | null;

  return (
    <div className="flex flex-col gap-6">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <Link
            href="/resumes"
            className="mb-1 inline-block text-xs text-zinc-400 hover:text-zinc-600"
          >
            ← Resumes
          </Link>
          <h1 className="text-2xl font-semibold text-zinc-900">{resume.title}</h1>
          {resume.originalFileName && (
            <p className="mt-0.5 text-sm text-zinc-400">{resume.originalFileName}</p>
          )}
        </div>
        <ParseStatusBadge status={resume.parseStatus} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {/* Basics + experience */}
        <div className="flex flex-col gap-4">
          {parsed ? (
            <>
              <section className="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                  Basics
                </h2>
                <p className="font-medium text-zinc-900">{parsed.basics.name}</p>
                <p className="text-sm text-zinc-500">{parsed.basics.headline}</p>
                <p className="mt-2 text-sm leading-relaxed text-zinc-700">
                  {parsed.basics.summary}
                </p>
              </section>

              <section className="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                  Experience
                </h2>
                <div className="flex flex-col gap-4">
                  {parsed.experience.map((exp, i) => (
                    <div key={i}>
                      <p className="font-medium text-zinc-900">{exp.title}</p>
                      <p className="text-xs text-zinc-500">
                        {exp.company} · {exp.duration}
                      </p>
                      <ul className="mt-2 flex flex-col gap-1">
                        {exp.highlights.map((h, j) => (
                          <li key={j} className="flex gap-2 text-sm text-zinc-700">
                            <span className="mt-0.5 shrink-0 text-zinc-300">•</span>
                            {h}
                          </li>
                        ))}
                      </ul>
                    </div>
                  ))}
                </div>
              </section>
            </>
          ) : (
            <div className="rounded-lg border border-dashed border-zinc-200 py-12 text-center">
              <p className="text-sm text-zinc-400">No structured data available.</p>
            </div>
          )}
        </div>

        {/* Skills, education, versions */}
        <div className="flex flex-col gap-4">
          {parsed && (
            <>
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

              <section className="rounded-lg border border-zinc-200 bg-white p-5">
                <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                  Education
                </h2>
                {parsed.education.map((edu, i) => (
                  <div key={i}>
                    <p className="font-medium text-zinc-900">{edu.degree}</p>
                    <p className="text-xs text-zinc-500">
                      {edu.institution} · {edu.year}
                    </p>
                  </div>
                ))}
              </section>
            </>
          )}

          {/* Versions list */}
          <section className="rounded-lg border border-zinc-200 bg-white p-5">
            <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-400">
              Versions
            </h2>
            {resume.versions.length === 0 ? (
              <p className="text-sm text-zinc-400">No versions.</p>
            ) : (
              <ul className="flex flex-col gap-2">
                {resume.versions.map((v) => (
                  <li
                    key={v.id}
                    className={`flex items-center justify-between rounded-md px-3 py-2 text-sm ${
                      v.id === latestVersion?.id
                        ? "bg-zinc-900 text-white"
                        : "bg-zinc-50 text-zinc-600"
                    }`}
                  >
                    <span>Version {v.versionNumber}</span>
                    <span className="text-xs opacity-70">
                      {new Date(v.createdAt).toLocaleDateString()}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {/* Latest version data preview */}
          {latestVersionData && (
            <section className="rounded-lg border border-zinc-200 bg-white p-5">
              <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-400">
                Latest Version — Basics
              </h2>
              <p className="text-sm text-zinc-500">{latestVersionData.basics.headline}</p>
              <p className="mt-1 text-sm leading-relaxed text-zinc-700">
                {latestVersionData.basics.summary}
              </p>
            </section>
          )}
        </div>
      </div>

      <p className="text-xs text-zinc-400">
        Added {new Date(resume.createdAt).toLocaleString()}
      </p>
    </div>
  );
}
