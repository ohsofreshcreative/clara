// @wordpress/* są zewnętrzne (wp.element, wp.apiFetch dostarcza WordPress), więc typy
// mapujemy na @types/react zamiast instalować pakiety tylko dla deklaracji.
declare module '@wordpress/element' {
  import type * as React from 'react';
  import type * as ReactDOMClient from 'react-dom/client';

  export const createElement: typeof React.createElement;
  export const Fragment: typeof React.Fragment;
  export const useState: typeof React.useState;
  export const useEffect: typeof React.useEffect;
  export const useMemo: typeof React.useMemo;
  export const useCallback: typeof React.useCallback;
  export const useRef: typeof React.useRef;
  export const createRoot: typeof ReactDOMClient.createRoot;
}

declare module '@wordpress/api-fetch' {
  export interface APIFetchOptions {
    path?: string;
    url?: string;
    method?: string;
    data?: unknown;
    body?: BodyInit;
    headers?: Record<string, string>;
    signal?: AbortSignal;
  }

  export default function apiFetch<T = unknown>(options: APIFetchOptions): Promise<T>;
}

declare module '*.jpg' {
  const src: string;
  export default src;
}

interface ClaraConfig {
  basePath: string;
  restNamespace: string;
  userName: string;
  preferences: { accent: string; scale: number; effects: string };
}

interface Window {
  claraConfig: ClaraConfig;
}
