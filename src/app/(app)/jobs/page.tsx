import Link from "next/link";
import { prisma } from "@/lib/prisma";
import { CreateJobForm } from "@/components/jobs/create-job-form";
import {
  ParseStatusBadge,
  ApplicationStatusBadge,
} from "@/components/ui/badges";

export default async function JobsPage() {
  const jobs = await prisma.job.findMany({
    orderBy: { createdAt: "desc" },
    include: { application: true },
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-semibold text-zinc-900">Jobs</h1>
        <CreateJobForm />
      </div>

      {jobs.length === 0 ? (
        <div className="rounded-lg border border-dashed border-zinc-200 py-16 text-center">
          <p className="text-sm text-zinc-400">No jobs yet. Add one above.</p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-zinc-100 bg-zinc-50 text-left">
                <th className="px-4 py-3 font-medium text-zinc-600">Role</th>
                <th className="px-4 py-3 font-medium text-zinc-600">Company</th>
                <th className="px-4 py-3 font-medium text-zinc-600">Status</th>
                <th className="px-4 py-3 font-medium text-zinc-600">Application</th>
                <th className="px-4 py-3 font-medium text-zinc-600">Added</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-zinc-100">
              {jobs.map((job) => (
                <tr key={job.id} className="hover:bg-zinc-50">
                  <td className="px-4 py-3">
                    <Link
                      href={`/jobs/${job.id}`}
                      className="font-medium text-zinc-900 hover:underline"
                    >
                      {job.title}
                    </Link>
                  </td>
                  <td className="px-4 py-3 text-zinc-600">{job.company}</td>
                  <td className="px-4 py-3">
                    <ParseStatusBadge status={job.parseStatus} />
                  </td>
                  <td className="px-4 py-3">
                    {job.application ? (
                      <ApplicationStatusBadge status={job.application.status} />
                    ) : (
                      <span className="text-zinc-400">—</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-zinc-400">
                    {new Date(job.createdAt).toLocaleDateString()}
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

