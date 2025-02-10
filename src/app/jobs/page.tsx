/**
 * /src/app/jobs/page.tsx
 *
 * Displays a list of saved job postings and provides a modal form to add new jobs.
 * Utilizes the JobForm and SavedJobs components for managing and interacting with job postings.
 *
 * Key Features:
 * - Lists saved jobs with options to generate job analysis and interview prep prompts.
 * - Integrates JobForm inside a modal for adding new job postings.
 * - Includes a search bar to filter job postings by title, company, or description.
 */

"use client";

import { useState, useEffect } from "react";
import { getJobPostings } from "@/utils/jobStorage";
import JobForm from "@/components/jobs/JobForm";
import SavedJobs from "@/components/jobs/SavedJobs";
import { JobPosting } from "@/types/jobPosting";
import Modal from "@/components/common/Modal";
import { toast } from "react-hot-toast";
import { PlusIcon } from "@heroicons/react/20/solid";

export default function JobsPage() {
  const [savedJobs, setSavedJobs] = useState<JobPosting[]>([]);
  const [filteredJobs, setFilteredJobs] = useState<JobPosting[]>([]);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");

  useEffect(() => {
    refreshJobs();
  }, []);

  useEffect(() => {
    handleSearch(searchQuery);
  }, [savedJobs, searchQuery]);

  const refreshJobs = () => {
    const jobs = getJobPostings();
    setSavedJobs(jobs);
  };

  const handleJobAdded = () => {
    refreshJobs();
    setIsModalOpen(false);
    toast.success("Job added successfully!");
  };

  const handleSearch = (query: string) => {
    const lowerQuery = query.toLowerCase();
    const filtered = savedJobs.filter(
      (job) =>
        job.jobTitle.toLowerCase().includes(lowerQuery) ||
        job.companyName.toLowerCase().includes(lowerQuery) ||
        job.jobDescription.toLowerCase().includes(lowerQuery)
    );
    setFilteredJobs(filtered);
  };

  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-4xl mx-auto flex justify-between items-center mb-4">
        <h1 className="text-2xl font-bold">Manage Job Postings</h1>
        <button
          onClick={() => setIsModalOpen(true)}
          className="flex items-center bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 transition">
          <PlusIcon className="w-5 h-5 mr-2" />
          Add Job
        </button>
      </div>
      <div className="max-w-4xl mx-auto bg-white shadow-lg rounded-xl p-6">
        {/* Search Bar */}
        <input
          type="text"
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          placeholder="Search jobs by title, company, or description..."
          className="w-full mb-6 p-3 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500"
        />

        {/* Modal for Adding New Jobs */}
        <Modal
          isOpen={isModalOpen}
          onClose={() => setIsModalOpen(false)}
          title="Add New Job">
          <JobForm onJobAdded={handleJobAdded} />
        </Modal>

        {/* List of Saved Jobs with Prompt Generation */}
        <SavedJobs jobs={filteredJobs} />
      </div>
    </main>
  );
}
