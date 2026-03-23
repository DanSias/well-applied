export default async function TailoredResumePage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return (
    <div>
      <h1 className="text-2xl font-semibold text-zinc-900">Tailored resume</h1>
      <p className="mt-2 text-sm text-zinc-500">
        Tailored resume placeholder — ID: {id}
      </p>
    </div>
  );
}
