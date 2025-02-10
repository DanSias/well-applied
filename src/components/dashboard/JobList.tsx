/**
 * JobList.tsx
 *
 * Renders a list of job postings with options to generate prompts for each job.
 * Utilizes the JobCard component for individual job display.
 *
 * Key Features:
 * - Maps through saved job postings and renders each using JobCard.
 * - Passes resume and cover letter data to each JobCard for prompt generation.
 * - Handles the empty state when no jobs are saved.
 */

"use client";

import JobCard from "@/components/dashboard/JobCard";
import { JobPosting } from "@/types/jobPosting";
import Link from "next/link";

interface JobListProps {
  jobs: JobPosting[];
  resume: string;
  coverLetter: string;
}

export default function JobList({ jobs, resume, coverLetter }: JobListProps) {
  return (
    <div>
      {jobs.length > 0 ? (
        jobs.map((job) => (
          <JobCard
            key={job.id}
            job={job}
            resume={resume}
            coverLetter={coverLetter}
          />
        ))
      ) : (
        <p className="text-gray-500">
          No job descriptions saved yet.{" "}
          <Link href="/jobs" className="text-blue-600 hover:underline">
            Add a job here
          </Link>
          .
        </p>
      )}
    </div>
  );
}
