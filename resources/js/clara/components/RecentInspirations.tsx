// Karty z mockupu były przykładowe; realne inspiracje przyjdą z etapów 06–07.
// Do tego czasu pokazujemy pusty stan z szkieletem siatki zamiast fikcyjnych danych.
export default function RecentInspirations() {
  return (
    <section aria-labelledby="clara-recent-title">
      <h2 id="clara-recent-title" className="text-xl font-semibold sm:text-2xl">Ostatnie inspiracje</h2>

      <div className="relative mt-4">
        <ul className="grid grid-cols-2 gap-3 xl:grid-cols-4" aria-hidden="true">
          {[0, 1, 2, 3].map((i) => (
            <li key={i} className="aspect-[16/10] rounded-2xl border border-white/5 bg-gradient-to-br from-white/[0.04] to-transparent" />
          ))}
        </ul>
        <p className="absolute inset-0 flex items-center justify-center px-6 text-center text-muted">
          Nie masz jeszcze inspiracji. Wybierz szybki start albo opisz sekcję powyżej.
        </p>
      </div>
    </section>
  );
}
