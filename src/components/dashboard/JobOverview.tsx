/**
 * JobOverview.tsx
 *
 * Displays a list of saved job descriptions with action buttons to generate tailored prompts.
 * Handles empty states when no jobs are saved and provides navigation to add new job descriptions.
 *
 * Key Features:
 * - Lists job titles, companies, and a preview of the job description.
 * - Provides prompt buttons for Cover Letter, Resume Tailoring, Interview Prep, and Company Insights.
 * - Retrieves and uses saved resume and cover letter data in prompts, prioritizing structured resumes.
 * - Replaces alerts with react-hot-toast for better user feedback.
 */

"use client";

import { getJobPostings } from "@/utils/jobStorage";
import { getResume, getStructuredResume } from "@/utils/resumeStorage";
import { getCoverLetter } from "@/utils/coverLetterStorage";
import { useState, useEffect } from "react";
import { JobPosting } from "@/types/jobPosting";
import JobList from "@/components/dashboard/JobList";

export default function JobOverview() {
  const [jobs, setJobs] = useState<JobPosting[]>([]);
  const [resume, setResume] = useState<string>("");
  const [coverLetter, setCoverLetter] = useState<string>("");

  useEffect(() => {
    const savedJobs = getJobPostings();
    const savedStructuredResume = getStructuredResume();
    const savedResume = savedStructuredResume
      ? formatStructuredResume(
          savedStructuredResume as unknown as Record<string, string>
        )
      : getResume();

    const savedCoverLetter = getCoverLetter();

    setJobs(savedJobs);
    setResume(savedResume);
    setCoverLetter(savedCoverLetter);
  }, []);

  const formatStructuredResume = (
    structuredResume: Record<string, string>
  ): string => {
    return Object.entries(structuredResume)
      .filter(([, value]) => typeof value === "string" && value.trim() !== "")
      .map(
        ([key, value]) => `${key.replace(/([A-Z])/g, " $1").trim()}\n${value}`
      )
      .join("\n\n");
  };

  return (
    <section className="mb-6">
      <h2 className="text-2xl font-semibold mb-2">Job Descriptions</h2>
      <JobList jobs={jobs} resume={resume} coverLetter={coverLetter} />
    </section>
  );
}
