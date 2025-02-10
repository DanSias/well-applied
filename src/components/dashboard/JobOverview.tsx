/**
 * JobOverview.tsx
 *
 * Displays a list of saved job descriptions with action buttons to generate tailored prompts.
 * Allows adding new jobs via a modal and filtering existing jobs with a search bar.
 * Handles empty states when no jobs are saved and provides navigation to add new job descriptions.
 *
 * Key Features:
 * - Lists job titles, companies, and a preview of the job description.
 * - Provides prompt buttons for Cover Letter, Resume Tailoring, Interview Prep, and Company Insights.
 * - Includes a modal to add new job descriptions and a search bar to filter jobs.
 * - Retrieves and uses saved resume and cover letter data in prompts, prioritizing structured resumes.
 * - Replaces alerts with react-hot-toast for better user feedback.
 */

"use client";

import { useState, useEffect } from "react";
import { getJobPostings } from "@/utils/jobStorage";
import { getResume, getStructuredResume } from "@/utils/resumeStorage";
import { getCoverLetter } from "@/utils/coverLetterStorage";
import { JobPosting } from "@/types/jobPosting";
import JobList from "@/components/dashboard/JobList";
import Modal from "@/components/common/Modal";
import JobForm from "@/components/jobs/JobForm";
import {
  PlusIcon,
  MagnifyingGlassIcon,
  XMarkIcon,
} from "@heroicons/react/24/solid";
import { toast } from "react-hot-toast";

export default function JobOverview() {
  const [jobs, setJobs] = useState<JobPosting[]>([]);
  const [resume, setResume] = useState<string>("");
  const [coverLetter, setCoverLetter] = useState<string>("");
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");

  useEffect(() => {
    refreshJobs();
    const savedStructuredResume = getStructuredResume();
    const savedResume = savedStructuredResume
      ? formatStructuredResume(
          savedStructuredResume as unknown as Record<string, string>
        )
      : getResume();

    const savedCoverLetter = getCoverLetter();

    setResume(savedResume);
    setCoverLetter(savedCoverLetter);
  }, []);

  const refreshJobs = () => {
    const savedJobs = getJobPostings();
    setJobs(savedJobs);
  };

  const handleJobAdded = () => {
    toast.success("Job successfully added!");
    setIsModalOpen(false);
    refreshJobs();
  };

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

  const filteredJobs = jobs.filter(
    (job) =>
      job.jobTitle.toLowerCase().includes(searchQuery.toLowerCase()) ||
      job.companyName.toLowerCase().includes(searchQuery.toLowerCase()) ||
      job.jobDescription.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <section className="mb-6">
      <div className="flex flex-col md:flex-row md:justify-between md:items-center mb-4 space-y-4 md:space-y-0">
        <h2 className="text-2xl font-semibold md:mr-4">
          Manage Job Applications
        </h2>

        {/* Search Bar */}
        <div className="relative flex-grow md:mx-4">
          <input
            type="text"
            placeholder="Search jobs..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
          />
          {searchQuery ? (
            <XMarkIcon
              className="w-5 h-5 absolute top-2.5 right-3 text-gray-400 cursor-pointer"
              onClick={() => setSearchQuery("")}
            />
          ) : (
            <MagnifyingGlassIcon className="w-5 h-5 absolute top-2.5 right-3 text-gray-400" />
          )}
        </div>

        <button
          onClick={() => setIsModalOpen(true)}
          className="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 flex items-center">
          <PlusIcon className="w-5 h-5 mr-2" /> Add Job
        </button>
      </div>

      {/* Job List */}
      <JobList jobs={filteredJobs} resume={resume} coverLetter={coverLetter} />

      {/* Job Creation Modal */}
      <Modal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)}>
        <div className="flex justify-between items-center border-b pb-2 mb-4 -mt-4">
          <h2 className="text-xl font-semibold">Add New Job</h2>
          <button
            onClick={() => setIsModalOpen(false)}
            className="text-gray-500 hover:text-gray-700 transition"
            aria-label="Close Modal">
            <XMarkIcon className="h-6 w-6" />
          </button>
        </div>

        {/* Job Form */}
        <JobForm onJobAdded={handleJobAdded} />
      </Modal>
    </section>
  );
}
