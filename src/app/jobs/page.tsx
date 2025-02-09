/**
 * /src/app/jobs/page.tsx
 *
 * Displays a list of saved job postings and provides a form to add new jobs.
 * Utilizes the JobForm component for adding jobs and updates the job list dynamically.
 *
 * Key Features:
 * - Lists saved jobs with options to view and manage them.
 * - Integrates JobForm for adding new job postings.
 */

"use client";

import { useState, useEffect } from "react";
import { getJobPostings } from "@/utils/jobStorage";
import JobForm from "@/components/jobs/JobForm";
import { JobPosting } from "@/types/jobPosting";

export default function JobsPage() {
  const [savedJobs, setSavedJobs] = useState<JobPosting[]>([]);

  useEffect(() => {
    refreshJobs();
  }, []);

  const refreshJobs = () => {
    const jobs = getJobPostings();
    setSavedJobs(jobs);
  };

  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-4xl mx-auto bg-white shadow-lg rounded-xl p-6">
        <h1 className="text-2xl font-bold mb-4">Manage Job Postings</h1>

        {/* Job Form for Adding New Jobs */}
        <JobForm onJobAdded={refreshJobs} />

        {/* List of Saved Jobs */}
        <h2 className="text-xl font-semibold mt-8 mb-2">Saved Jobs</h2>
        {savedJobs.length > 0 ? (
          savedJobs.map((job) => (
            <div
              key={job.id}
              className="p-4 border rounded-md mb-4 bg-gray-50 hover:bg-gray-100 transition">
              <h3 className="text-lg font-bold">
                {job.jobTitle} at {job.companyName}
              </h3>
              <p className="text-gray-600 mt-2">
                {job.jobDescription.slice(0, 150)}...
              </p>
            </div>
          ))
        ) : (
          <p className="text-gray-500">No saved job postings yet.</p>
        )}
      </div>
    </main>
  );
}
