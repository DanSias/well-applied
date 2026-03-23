"use server";

import { redirect } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { getDevUser } from "@/lib/dev-user";
import { mockParsedJob } from "@/lib/mock-data";

export async function createJob(formData: FormData) {
  const title = (formData.get("title") as string | null)?.trim() ?? "";
  const company = (formData.get("company") as string | null)?.trim() ?? "";
  const rawDescription = (formData.get("rawDescription") as string | null)?.trim() ?? "";

  if (!title || !company || !rawDescription) {
    throw new Error("All fields are required.");
  }

  const user = await getDevUser();
  // JSON round-trip satisfies Prisma's InputJsonValue index signature requirement
  const parsedStructured = JSON.parse(
    JSON.stringify(mockParsedJob(title, company, rawDescription)),
  );

  const job = await prisma.job.create({
    data: {
      userId: user.id,
      title,
      company,
      rawDescription,
      parsedStructured,
      parseStatus: "COMPLETED",
      application: {
        create: {
          userId: user.id,
          status: "SAVED",
        },
      },
    },
  });

  redirect(`/jobs/${job.id}`);
}
