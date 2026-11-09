import { parseBundle, validateBundle, type Bundle } from './bundle.ts';
import { importBundle } from './importer.ts';

figma.showUI(__html__, { width: 420, height: 560, themeColors: true });

type Message = { type: 'import'; text?: string; bundle?: Bundle; replace?: boolean } | { type: 'close' };

figma.ui.onmessage = async (msg: Message) => {
  if (msg.type === 'close') {
    figma.closePlugin();
    return;
  }

  if (msg.type !== 'import') return;

  const parsed = msg.text !== undefined ? parseBundle(msg.text) : { bundle: msg.bundle, errors: validateBundle(msg.bundle) };
  if (!parsed.bundle || parsed.errors.length) {
    figma.ui.postMessage({ type: 'report', report: { ok: false, errors: parsed.errors } });
    return;
  }

  try {
    const report = await importBundle(figma, parsed.bundle, { replace: msg.replace === true });
    figma.ui.postMessage({ type: 'report', report });
    if (report.ok) figma.notify(`Clara: utworzono „${report.frameName}”`);
  } catch (error) {
    figma.ui.postMessage({ type: 'report', report: { ok: false, errors: [error instanceof Error ? error.message : String(error)] } });
  }
};
