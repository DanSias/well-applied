/**
 * jobStorage.ts
 *
 * Utility functions for managing job postings in localStorage.
 * Provides methods to save, retrieve, and delete job postings,
 * ensuring data persistence across user sessions.
 *
 * Key Features:
 * - `saveJobPosting`: Adds a new job posting to localStorage.
 * - `getJobPostings`: Retrieves all saved job postings from localStorage.
 * - `deleteJobPosting`: Removes a job posting by its unique ID.
 */

import { JobPosting } from "@/types/jobPosting";

const JOB_POSTINGS_KEY = "jobPostings";

/**
 * Save a new job posting to localStorage.
 * Ensures the code runs in the browser before accessing localStorage.
 *
 * @param newJob - The job posting object to be saved.
 */
export const saveJobPosting = (newJob: JobPosting) => {
  if (typeof window !== "undefined") {
    const existingJobs = getJobPostings();
    existingJobs.push(newJob);
    localStorage.setItem(JOB_POSTINGS_KEY, JSON.stringify(existingJobs));
  }
};

/**
 * Retrieve all saved job postings from localStorage.
 * Returns an empty array if no postings are found or if running on the server.
 *
 * @returns An array of saved job postings.
 */
export const getJobPostings = (): JobPosting[] => {
  if (typeof window !== "undefined") {
    return JSON.parse(localStorage.getItem(JOB_POSTINGS_KEY) || "[]");
  }
  return [];
};

/**
 * Delete a job posting from localStorage by its unique ID.
 * Ensures the code runs in the browser before accessing localStorage.
 *
 * @param id - The unique identifier of the job posting to delete.
 */
export const deleteJobPosting = (id: string) => {
  if (typeof window !== "undefined") {
    const updatedJobs = getJobPostings().filter((job) => job.id !== id);
    localStorage.setItem(JOB_POSTINGS_KEY, JSON.stringify(updatedJobs));
  }
};
