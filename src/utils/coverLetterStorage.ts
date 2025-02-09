const COVER_LETTER_KEY = "userCoverLetter";

/**
 * Save the user's cover letter to localStorage.
 * @param coverLetterText - The text content of the cover letter.
 */
export const saveCoverLetter = (coverLetterText: string) => {
  localStorage.setItem(COVER_LETTER_KEY, coverLetterText);
};

/**
 * Retrieve the user's saved cover letter from localStorage.
 * @returns The saved cover letter text or an empty string if not found.
 */
export const getCoverLetter = (): string => {
  return localStorage.getItem(COVER_LETTER_KEY) || "";
};

/**
 * Clear the user's saved cover letter from localStorage.
 */
export const clearCoverLetter = () => {
  localStorage.removeItem(COVER_LETTER_KEY);
};
