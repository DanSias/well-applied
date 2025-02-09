import ResumeInputForm from "@/components/ResumeInputForm";
import ResumeStructuredForm from "@/components/ResumeStructuredForm";

export default function ResumePage() {
  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-2 gap-8">
        {/* Simple Resume Input */}
        <div className="bg-white shadow-lg rounded-xl p-6">
          <h2 className="text-xl font-bold mb-4">Simple Resume Upload</h2>
          <p className="text-gray-600 mb-4">
            Quickly paste your full resume into a single field. Ideal for fast
            improvements and quick tailoring.
          </p>
          <ResumeInputForm />
        </div>

        {/* Advanced Resume Input */}
        <ResumeStructuredForm />
      </div>
    </main>
  );
}
