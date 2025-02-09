/**
 * PromptsPage
 *
 * The main page for generating tailored prompts for resumes, cover letters, and interview preparation.
 * Integrates job selection, prompt type selection, clarifying question options, and refinement phrases into a cohesive workflow.
 *
 * Key Features:
 * - Allows users to select saved job postings.
 * - Provides options to choose prompt type and clarifying questions.
 * - Displays generated prompts with copy-to-clipboard functionality.
 * - Offers common refinement phrases to enhance AI-generated responses.
 *
 * File Location: /src/app/prompts/page.tsx
 */

"use client";

import { useState, useEffect } from "react";
import JobSelector from "@/components/prompts/JobSelector";
import PromptTypeSelector from "@/components/prompts/PromptTypeSelector";
import ClarifyingQuestionsSelector from "@/components/prompts/ClarifyingQuestionsSelector";
import GeneratedPromptDisplay from "@/components/prompts/GeneratedPromptDisplay";
import RefinementPhrases from "@/components/prompts/RefinementPhrases";
import { getResume } from "@/utils/resumeStorage";
import { JobPosting } from "@/types/jobPosting";

export default function PromptsPage() {
  const [selectedJob, setSelectedJob] = useState<JobPosting | null>(null);
  const [promptType, setPromptType] = useState<
    "resume" | "coverLetter" | "interview"
  >("resume");
  const [numClarifyingQuestions, setNumClarifyingQuestions] =
    useState<number>(0);
  const [generatedPrompt, setGeneratedPrompt] = useState<string>("");
  const [resume, setResume] = useState<string>("");

  useEffect(() => {
    setResume(getResume());
  }, []);

  useEffect(() => {
    if (selectedJob) {
      generatePrompt(selectedJob, promptType, numClarifyingQuestions);
    }
  }, [selectedJob, promptType, numClarifyingQuestions]);

  const generatePrompt = (
    job: JobPosting,
    type: string,
    clarifyingQuestions: number
  ) => {
    let prompt = "";

    if (clarifyingQuestions > 0) {
      prompt += `Before generating the response, ask me ${clarifyingQuestions} clarifying ${
        clarifyingQuestions === 1 ? "question" : "questions"
      } to gather additional relevant information that may not be in my resume or the job description.\n\n`;
    }

    switch (type) {
      case "resume":
        prompt += `Help me tailor my resume for the role of ${job.jobTitle} at ${job.companyName}.
Here is my resume: ${resume}.
Here is the job description: ${job.jobDescription}.
Highlight relevant skills and experiences, and suggest improvements for better alignment.`;
        break;
      case "coverLetter":
        prompt += `Write a personalized cover letter for the ${job.jobTitle} position at ${job.companyName}.
Here is my resume: ${resume}.
Align my skills and experiences with the following job description: ${job.jobDescription}.`;
        break;
      case "interview":
        prompt += `Generate common interview questions for the ${job.jobTitle} role at ${job.companyName}.
Provide guidance on how to answer based on my resume: ${resume} and the job description: ${job.jobDescription}.`;
        break;
      default:
        prompt = "";
    }

    setGeneratedPrompt(prompt);
  };

  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-5xl mx-auto bg-white shadow-lg rounded-xl p-6">
        <h1 className="text-3xl font-bold mb-6">Generate Tailored Prompts</h1>

        {/* Job Selector Component */}
        <JobSelector
          onJobSelect={setSelectedJob}
          selectedJobId={selectedJob?.id || null}
        />

        {/* Prompt Type Selector Component */}
        <PromptTypeSelector
          selectedPromptType={promptType}
          onPromptTypeChange={setPromptType}
        />

        {/* Clarifying Questions Selector Component */}
        <ClarifyingQuestionsSelector
          numClarifyingQuestions={numClarifyingQuestions}
          onClarifyingQuestionsChange={setNumClarifyingQuestions}
        />

        {/* Generated Prompt Display Component */}
        {generatedPrompt && (
          <GeneratedPromptDisplay generatedPrompt={generatedPrompt} />
        )}

        {/* Refinement Phrases Component */}
        <RefinementPhrases />
      </div>
    </main>
  );
}
