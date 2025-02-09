/**
 * resume.ts
 *
 * TypeScript interfaces for defining the structure of resume data across the application.
 * Supports both simple and structured resumes for better type safety and maintainability.
 *
 * Key Features:
 * - `ResumeData`: Defines the structure for sectioned resume input (e.g., contact info, work experience).
 */

export interface ResumeData {
  contactInfo: string;
  professionalSummary: string;
  workExperience: string;
  education: string;
  skills: string;
  certifications: string;
  projects: string;
  volunteerExperience: string;
  languages: string;
}
