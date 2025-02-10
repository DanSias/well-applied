/**
 * /src/app/jobs/page.tsx
 *
 * Displays a list of saved job postings and provides a modal form to add new jobs.
 * Utilizes the JobForm and SavedJobs components for managing and interacting with job postings.
 *
 * Key Features:
 * - Lists saved jobs with options to generate job analysis and interview prep prompts.
 * - Integrates JobForm inside a modal for adding new job postings.
 */

"use client";

import { useState, useEffect } from "react";
import { getJobPostings } from "@/utils/jobStorage";
import JobForm from "@/components/jobs/JobForm";
import SavedJobs from "@/components/jobs/SavedJobs";
import { JobPosting } from "@/types/jobPosting";
import Modal from "@/components/common/Modal";
import { toast } from "react-hot-toast";

export default function JobsPage() {
  const [savedJobs, setSavedJobs] = useState<JobPosting[]>([]);
  const [isModalOpen, setIsModalOpen] = useState(false);

  useEffect(() => {
    refreshJobs();
  }, []);

  const refreshJobs = () => {
    const jobs = getJobPostings();
    setSavedJobs(jobs);
  };

  const handleJobAdded = () => {
    refreshJobs();
    setIsModalOpen(false);
    toast.success("Job added successfully!");
  };

  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-4xl mx-auto ">
        <div className="flex justify-between">
          <h1 className="text-3xl font-bold mb-4">Manage Job Postings</h1>
          <button
            onClick={() => setIsModalOpen(true)}
            className="mb-4 bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 transition">
            Add Job
          </button>
        </div>
        <div className="bg-white shadow-lg rounded-xl p-6">
          {/* Add Job Button to Open Modal */}

          {/* Modal for Adding New Jobs */}
          <Modal
            isOpen={isModalOpen}
            onClose={() => setIsModalOpen(false)}
            title="Add New Job">
            <JobForm onJobAdded={handleJobAdded} />
          </Modal>

          {/* List of Saved Jobs with Prompt Generation */}
          <SavedJobs jobs={savedJobs} />
        </div>
      </div>
    </main>
  );
}
