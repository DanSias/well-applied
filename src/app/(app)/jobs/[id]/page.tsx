export default async function JobDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return (
    <div>
      <h1 className="text-2xl font-semibold text-zinc-900">Job detail</h1>
      <p className="mt-2 text-sm text-zinc-500">
        Job detail placeholder — ID: {id}
      </p>
    </div>
  );
}
