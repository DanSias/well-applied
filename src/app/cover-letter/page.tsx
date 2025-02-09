import CoverLetterInputForm from "@/components/CoverLetterInputForm";

export default function CoverLetterPage() {
  return (
    <main className="min-h-screen bg-gray-50 p-8 flex items-center justify-center">
      <div className="max-w-3xl w-full bg-white shadow-lg rounded-xl p-6">
        <h1 className="text-2xl font-bold mb-4">Your Cover Letter</h1>
        <p className="text-gray-600 mb-4">
          Paste your existing cover letter below. It will be saved automatically
          and used as a reference for generating job-specific cover letters.
        </p>
        <CoverLetterInputForm />
      </div>
    </main>
  );
}
