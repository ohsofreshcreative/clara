import { useEffect, useState } from '@wordpress/element';
import { errorMessage, getStatus } from './api';
import { useLocation } from './router';
import AmbientBackground from './components/AmbientBackground';
import Sidebar from './components/Sidebar';
import Collections from './pages/Collections';
import Dashboard from './pages/Dashboard';
import Placeholder from './pages/Placeholder';
import ProjectDetail from './pages/ProjectDetail';
import Settings from './pages/Settings';
import Projects from './pages/Projects';

function Page() {
  const { route, params } = useLocation();

  if (!route || params.length > 1) return <Placeholder title="Nie znaleziono strony" />;
  if (route.path === '') return params.length ? <Placeholder title="Nie znaleziono strony" /> : <Dashboard />;

  if (route.path === 'projects') {
    if (!params.length) return <Projects />;
    const id = Number(params[0]);
    return Number.isInteger(id) && id > 0 ? <ProjectDetail key={id} id={id} /> : <Placeholder title="Nie znaleziono strony" />;
  }

  if (route.path === 'settings') return params.length ? <Placeholder title="Nie znaleziono strony" /> : <Settings />;

  if (route.path === 'collections') return params.length ? <Placeholder title="Nie znaleziono strony" /> : <Collections />;

  return <Placeholder title={route.label} />;
}

export default function App() {
  const { route } = useLocation();
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    getStatus()
      .then((status) => !status.schemaCurrent && setNotice('Schemat bazy Clara wymaga migracji (wp clara migrate).'))
      .catch((e: unknown) => setNotice(errorMessage(e)));
  }, []);

  useEffect(() => {
    document.title = route ? `${route.label} · Clara` : 'Clara';
    window.scrollTo(0, 0);
    document.getElementById('clara-main')?.scrollTo(0, 0);
  }, [route, window.location.pathname]);

  return (
    <div className="relative isolate min-h-screen lg:flex">
      <AmbientBackground />
      <a href="#clara-main" className="sr-only z-50 rounded-lg bg-accent-600 px-4 py-2 focus:not-sr-only focus:fixed focus:top-4 focus:left-4">
        Przejdź do treści
      </a>

      <Sidebar current={route} userName={window.claraConfig.userName} />

      <main id="clara-main" tabIndex={-1} className="min-w-0 flex-1 focus:outline-none lg:h-screen lg:overflow-y-auto">
        {notice && (
          <p role="alert" className="m-4 rounded-xl border border-red-400/40 bg-red-950/40 px-4 py-3 text-red-200">
            {notice}
          </p>
        )}
        <Page />
      </main>
    </div>
  );
}
