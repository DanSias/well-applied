"use client";

import { useState, useRef } from "react";
import { exampleResume } from "@/data/exampleResume";
import { Resume } from "@/types/resumeBuilder";
import ResumeEditorForm from "@/components/resumeBuilder/ResumeEditorForm";
import ResumePreview from "@/components/resumeBuilder/ResumePreview";
import PrintButton from "@/components/resumeBuilder/PrintButton";

export default function ResumeBuilderPage() {
  const [resume, setResume] = useState<Resume>(exampleResume);
  const previewRef = useRef<HTMLDivElement>(null);

  return (
    <main className="min-h-screen p-8 bg-gray-50">
      <h1 className="text-3xl font-bold text-center mb-8">Resume Builder</h1>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {/* Editor Form */}
        <div className="bg-white p-6 rounded-lg shadow-md overflow-y-auto max-h-[85vh]">
          <ResumeEditorForm resume={resume} setResume={setResume} />
        </div>

        {/* Preview + Print */}
        <div className="bg-white p-6 rounded-lg shadow-md flex flex-col items-center">
          <div ref={previewRef} className="w-full">
            <ResumePreview resume={resume} />
          </div>
          <div className="mt-4">
            <PrintButton targetRef={previewRef} />
          </div>
        </div>
      </div>
    </main>
  );
}
