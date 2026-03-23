export default async function ResumeDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return (
    <div>
      <h1 className="text-2xl font-semibold text-zinc-900">Resume detail</h1>
      <p className="mt-2 text-sm text-zinc-500">
        Resume detail placeholder — ID: {id}
      </p>
    </div>
  );
}
