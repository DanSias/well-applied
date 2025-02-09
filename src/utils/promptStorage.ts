import { SavedPrompt } from "@/types/prompt";

const PROMPTS_KEY = "savedPrompts";

/**
 * Save a new generated prompt to localStorage.
 * @param newPrompt The new prompt object to save.
 */
export const savePrompt = (newPrompt: SavedPrompt) => {
  const existingPrompts = getSavedPrompts();
  existingPrompts.push(newPrompt);
  localStorage.setItem(PROMPTS_KEY, JSON.stringify(existingPrompts));
};

/**
 * Retrieve all saved prompts from localStorage.
 * @returns An array of saved prompts.
 */

export const getSavedPrompts = (): SavedPrompt[] => {
  return JSON.parse(localStorage.getItem(PROMPTS_KEY) || "[]") as SavedPrompt[];
};
