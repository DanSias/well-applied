import Link from "next/link";
import { prisma } from "@/lib/prisma";
import { CreateResumeForm } from "@/components/resumes/create-resume-form";
import { ParseStatusBadge } from "@/components/ui/badges";

export default async function ResumesPage() {
  const resumes = await prisma.resume.findMany({
    orderBy: { createdAt: "desc" },
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-zinc-900">Resumes</h1>
        <CreateResumeForm />
      </div>

      {resumes.length === 0 ? (
        <div className="rounded-lg border border-dashed border-zinc-200 py-16 text-center">
          <p className="text-sm text-zinc-400">No resumes yet. Add one above.</p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                <th className="px-4 py-3 font-medium text-zinc-600">Title</th>
                <th className="px-4 py-3 font-medium text-zinc-600">Status</th>
                <th className="px-4 py-3 font-medium text-zinc-600">Default</th>
                <th className="px-4 py-3 font-medium text-zinc-600">Added</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100">
              {resumes.map((resume) => (
                <tr key={resume.id} className="hover:bg-zinc-50">
                  <td className="px-4 py-3">
                    <Link
                      href={`/resumes/${resume.id}`}
                      className="font-medium text-zinc-900 hover:underline"
                    >
                      {resume.title}
                    </Link>
                    {resume.originalFileName && (
                      <p className="mt-0.5 text-xs text-zinc-400">
                        {resume.originalFileName}
                      </p>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <ParseStatusBadge status={resume.parseStatus} />
                  </td>
                  <td className="px-4 py-3">
                    {resume.isDefault ? (
                      <span className="text-xs font-medium text-green-700">
                        Default
                      </span>
                    ) : (
                      <span className="text-zinc-400">—</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-zinc-400">
                    {new Date(resume.createdAt).toLocaleDateString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
