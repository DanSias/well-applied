"use client";

import { useEffect, useState } from "react";
import { getSavedPrompts } from "@/utils/promptStorage";
import { SavedPrompt } from "@/types/prompt";

export default function SavedPromptsPage() {
  const [savedPrompts, setSavedPrompts] = useState<SavedPrompt[]>([]);

  useEffect(() => {
    const prompts = getSavedPrompts();
    setSavedPrompts(prompts);
  }, []);

  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-3xl mx-auto bg-white shadow-lg rounded-xl p-6">
        <h1 className="text-2xl font-bold mb-4">Saved Prompts</h1>

        {savedPrompts.length > 0 ? (
          savedPrompts.map((prompt) => (
            <div
              key={prompt.id}
              className="p-4 border rounded-md mb-4 bg-gray-50">
              <h3 className="text-lg font-bold">
                {prompt.jobTitle} at {prompt.companyName}
              </h3>
              <textarea
                value={prompt.promptText}
                readOnly
                className="w-full p-4 border rounded-md bg-gray-100 mt-2"
                rows={6}
              />
            </div>
          ))
        ) : (
          <p className="text-gray-500">No prompts saved yet.</p>
        )}
      </div>
    </main>
  );
}
