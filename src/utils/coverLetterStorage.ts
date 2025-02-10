/**
 * coverLetterStorage.ts
 *
 * Utility functions for managing cover letter data in localStorage.
 * Provides methods to save, retrieve, and clear the user's cover letter,
 * ensuring persistence of the cover letter across sessions.
 *
 * Key Features:
 * - `saveCoverLetter`: Stores the user's cover letter in localStorage.
 * - `getCoverLetter`: Retrieves the saved cover letter from localStorage.
 * - `clearCoverLetter`: Removes the cover letter from localStorage.
 */

const COVER_LETTER_KEY = "coverLetter";

/**
 * Save the user's cover letter to localStorage.
 * Ensures the code runs in the browser before accessing localStorage.
 *
 * @param coverLetterText - The text content of the cover letter.
 */
export const saveCoverLetter = (coverLetterText: string) => {
  if (typeof window !== "undefined") {
    localStorage.setItem(COVER_LETTER_KEY, coverLetterText);
  }
};

/**
 * Retrieve the user's saved cover letter from localStorage.
 * Returns an empty string if no cover letter is found or if running on the server.
 *
 * @returns The saved cover letter text.
 */
export const getCoverLetter = (): string => {
  if (typeof window !== "undefined") {
    return localStorage.getItem(COVER_LETTER_KEY) || "";
  }
  return "";
};

/**
 * Clear the user's saved cover letter from localStorage.
 * Ensures the code runs in the browser before accessing localStorage.
 */
export const clearCoverLetter = () => {
  if (typeof window !== "undefined") {
    localStorage.removeItem(COVER_LETTER_KEY);
  }
};
