// /src/types/jobPosting.ts

export interface JobPosting {
  id: string; // Unique identifier (UUID)
  jobTitle: string; // e.g., Software Engineer
  companyName: string; // e.g., TechCorp
  jobDescription: string; // Full job description text
  createdAt: string; // ISO date string for when the job was saved
}
