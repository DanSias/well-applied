"use server";

import { redirect } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { getDevUser } from "@/lib/dev-user";
import { mockParsedResume } from "@/lib/mock-data";

export async function createResume(formData: FormData) {
  const title = (formData.get("title") as string | null)?.trim() ?? "";
  const originalFileName = (formData.get("originalFileName") as string | null)?.trim() || null;
  const sourceText = (formData.get("sourceText") as string | null)?.trim() ?? "";

  if (!title || !sourceText) {
    throw new Error("Title and source text are required.");
  }

  const user = await getDevUser();
  // JSON round-trip satisfies Prisma's InputJsonValue index signature requirement
  const structuredData = JSON.parse(
    JSON.stringify(mockParsedResume(title, sourceText)),
  );

  const resume = await prisma.resume.create({
    data: {
      userId: user.id,
      title,
      originalFileName,
      parseStatus: "COMPLETED",
      structuredData,
      versions: {
        create: {
          versionNumber: 1,
          structuredData,
        },
      },
    },
  });

  redirect(`/resumes/${resume.id}`);
}
