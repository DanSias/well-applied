import CoverLetterInputForm from "@/components/coverLetter/CoverLetterInputForm";
import CoverLetterPrompts from "@/components/coverLetter/CoverLetterPrompts";

export default function CoverLetterPage() {
  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-3xl mx-auto">
        <h1 className="text-2xl font-bold mb-4 text-center">
          Strengthen Your Cover Letter
        </h1>

        <p className="text-gray-600 mb-6 text-center">
          Paste your existing cover letter below. We&apos;ll automatically save
          it and use it to generate tailored, job-specific versions that
          highlight your strengths.
        </p>
      </div>
      <div className="max-w-4xl mx-auto  mb-4">
        <div className="bg-white p-6 rounded">
          <p className="text-gray-600 mb-4"></p>
          <CoverLetterPrompts />
          <CoverLetterInputForm />
        </div>
      </div>
    </main>
  );
}
