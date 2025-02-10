/**
 * resumeStorage.ts
 *
 * Utility functions for managing resume data in localStorage. Supports both simple full-text resumes
 * and structured resumes with individual sections.
 *
 * Key Features:
 * - `saveResume`, `getResume`, `clearResume`: Handle simple, full-text resume storage.
 * - `saveStructuredResume`, `getStructuredResume`, `clearStructuredResume`: Manage structured resume data with type safety.
 * - Client-Side Check: Ensures safe usage with SSR in Next.js.
 */

const RESUME_KEY = "userResume";

/**
 * Save the user's resume to localStorage.
 * @param resumeText - The text content of the resume.
 */
export const saveResume = (resumeText: string) => {
  if (typeof window !== "undefined") {
    localStorage.setItem(RESUME_KEY, resumeText);
  }
};

/**
 * Retrieve the user's saved resume from localStorage.
 * @returns The saved resume text or an empty string if not found.
 */
export const getResume = (): string => {
  if (typeof window !== "undefined") {
    return localStorage.getItem(RESUME_KEY) || "";
  }
  return "";
};

/**
 * Clear the user's saved resume from localStorage.
 */
export const clearResume = () => {
  if (typeof window !== "undefined") {
    localStorage.removeItem(RESUME_KEY);
  }
};

// Structured Resume - Split by Section
import { ResumeData } from "@/types/resume";

const STRUCTURED_RESUME_KEY = "structuredResume";

/**
 * Save structured resume data to localStorage.
 * @param resumeData - The structured resume data object (type-safe).
 */
export const saveStructuredResume = (resumeData: ResumeData) => {
  if (typeof window !== "undefined") {
    localStorage.setItem(STRUCTURED_RESUME_KEY, JSON.stringify(resumeData));
  }
};

/**
 * Retrieve structured resume data from localStorage.
 * @returns The saved structured resume data or null if not found.
 */
export const getStructuredResume = (): ResumeData | null => {
  if (typeof window !== "undefined") {
    const data = localStorage.getItem(STRUCTURED_RESUME_KEY);
    return data ? (JSON.parse(data) as ResumeData) : null;
  }
  return null;
};

/**
 * Clear the structured resume data from localStorage.
 */
export const clearStructuredResume = () => {
  if (typeof window !== "undefined") {
    localStorage.removeItem(STRUCTURED_RESUME_KEY);
  }
};
