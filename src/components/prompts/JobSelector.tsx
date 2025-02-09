/**
 * JobSelector.tsx
 *
 * Component for displaying and selecting saved job postings using a dropdown menu.
 * Utilizes react-select for a clean, searchable dropdown interface.
 *
 * Key Features:
 * - Displays job title and company name in the dropdown.
 * - Allows users to search and select a job for prompt generation.
 * - Provides visual feedback for the selected job.
 *
 * File Location: /src/components/prompts/JobSelector.tsx
 */

"use client";

import { useState, useEffect } from "react";
import Select, { SingleValue } from "react-select";
import { getJobPostings } from "@/utils/jobStorage";
import { JobPosting } from "@/types/jobPosting";

interface JobSelectorProps {
  onJobSelect: (job: JobPosting) => void;
  selectedJobId: string | null;
}

export default function JobSelector({
  onJobSelect,
  selectedJobId,
}: JobSelectorProps) {
  const [savedJobs, setSavedJobs] = useState<JobPosting[]>([]);

  useEffect(() => {
    const jobs = getJobPostings();
    setSavedJobs(jobs);
  }, []);

  const jobOptions = savedJobs.map((job) => ({
    value: job.id,
    label: `${job.jobTitle} at ${job.companyName}`,
    description: job.jobDescription,
  }));

  const handleChange = (
    selectedOption: SingleValue<{
      value: string;
      label: string;
      description: string;
    }>
  ) => {
    if (selectedOption) {
      const selectedJob = savedJobs.find(
        (job) => job.id === selectedOption.value
      );
      if (selectedJob) {
        onJobSelect(selectedJob);
      }
    }
  };

  const selectedOption =
    jobOptions.find((option) => option.value === selectedJobId) || null;

  return (
    <div className="mb-6">
      <h2 className="text-xl font-semibold mb-2">Select a Job Posting</h2>
      <Select
        options={jobOptions}
        value={selectedOption}
        onChange={handleChange}
        placeholder="Select a job..."
        className="text-gray-800"
      />
    </div>
  );
}
