// /src/types/prompt.ts

/**
 * @interface PromptData
 *
 * Represents the structure of a saved prompt in the Well Applied app.
 * This interface ensures that data stored in localStorage is consistent
 * and easy to manage. Keeping types well-defined helps future developers
 * (and hiring managers reviewing your code!) understand the data flow.
 *
 * Fields:
 * - id: Unique identifier for each prompt (use UUID for uniqueness).
 * - category: Defines the prompt type—Resume, Company Research, or Interview Prep.
 * - jobTitle: The specific job title the prompt is tailored for.
 * - companyName: (Optional) Name of the company for which the prompt is generated.
 * - jobDescription: (Optional) Detailed job description provided by the user.
 * - promptText: The final AI prompt generated based on user inputs.
 * - createdAt: Timestamp of when the prompt was created (ISO 8601 format).
 */

export interface SavedPrompt {
  id: string;
  jobTitle: string;
  companyName: string;
  promptText: string;
  createdAt: string;
}
