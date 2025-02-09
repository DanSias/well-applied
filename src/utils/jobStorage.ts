import { JobPosting } from "@/types/jobPosting";

const JOB_POSTINGS_KEY = "jobPostings";

/**
 * Save a new job posting to localStorage.
 * @param newJob The new job posting object to save.
 */
export const saveJobPosting = (newJob: JobPosting) => {
  const existingJobs = getJobPostings();
  existingJobs.push(newJob);
  localStorage.setItem(JOB_POSTINGS_KEY, JSON.stringify(existingJobs));
};

/**
 * Retrieve all saved job postings from localStorage.
 * @returns An array of saved job postings.
 */
export const getJobPostings = (): JobPosting[] => {
  return JSON.parse(localStorage.getItem(JOB_POSTINGS_KEY) || "[]");
};

/**
 * Delete a job posting by its ID.
 * @param id The unique ID of the job posting to delete.
 */
export const deleteJobPosting = (id: string) => {
  const updatedJobs = getJobPostings().filter((job) => job.id !== id);
  localStorage.setItem(JOB_POSTINGS_KEY, JSON.stringify(updatedJobs));
};
