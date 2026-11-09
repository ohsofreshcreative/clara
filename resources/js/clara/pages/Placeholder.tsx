export default function Placeholder({ title }: { title: string }) {
  return (
    <div className="mx-auto max-w-[1240px] px-4 py-12 sm:px-8 lg:px-12">
      <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{title}</h1>
      <p className="mt-4 text-muted">Ten moduł zostanie dodany w kolejnych etapach PoC.</p>
    </div>
  );
}
