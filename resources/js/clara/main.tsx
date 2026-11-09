import { createRoot } from '@wordpress/element';
import App from './App';

const root = document.getElementById('clara-root');

if (root) {
  createRoot(root).render(<App />);
}
