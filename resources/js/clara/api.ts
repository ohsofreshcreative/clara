import apiFetch from '@wordpress/api-fetch';

export interface ClaraStatus {
  app: 'clara';
  schemaVersion: string | null;
  schemaCurrent: boolean;
  tables: Record<string, boolean>;
  user: { id: number; name: string };
}

export interface Attachment {
  id: number;
  name: string;
  mime: string;
  size: number;
  url: string;
}

export interface Brief {
  demo?: boolean;
  summary?: string;
  audience?: string;
  goals?: string[];
  tone?: string[];
  mustHave?: string[];
  avoid?: string[];
  attachments?: Attachment[];
  direction?: ProjectDirection;
}

/** Obrany kierunek projektu — wariant, którego stylu trzymają się kolejne generowania. */
export interface ProjectDirection {
  generationId: number;
  sectionId: number;
  title: string;
  layout: string;
  imageUrl: string;
  setAt: string;
}

export interface Requirements {
  goal?: string;
  content?: string[];
  hardConstraints?: string[];
  softConstraints?: string[];
  notes?: string;
}

export interface Section {
  id: number;
  project_id: number;
  type: string;
  type_label: string;
  name: string;
  status: 'draft' | 'ready' | 'archived';
  requirements: Requirements;
  selected_generation_id: number | null;
  created_at: string;
}

export interface Project {
  id: number;
  name: string;
  client_name: string;
  industry: string;
  website_url: string;
  figma_url: string;
  brief: Brief;
  created_at: string;
  updated_at: string;
  sections?: Section[];
  design_system_version?: number | null;
}

export type ProjectInput = Partial<Pick<Project, 'name' | 'client_name' | 'industry' | 'website_url' | 'figma_url'>> & {
  brief?: Omit<Brief, 'attachments' | 'demo'>;
};

export interface SectionInput {
  type?: string;
  name?: string;
  status?: Section['status'];
  requirements?: Requirements;
}

export interface ColorToken { name: string; value: string }
export interface TypographyToken { name: string; fontFamily: string; fontSize: number | null; fontWeight: number; lineHeight: number | null }
export interface ValueToken { name: string; value: number | null }
export interface ShadowToken { name: string; value: string }

export interface Tokens {
  colors: ColorToken[];
  typography: TypographyToken[];
  spacing: ValueToken[];
  radii: ValueToken[];
  shadows: ShadowToken[];
}

export interface DesignSystemSource {
  type: 'manual' | 'json_import' | 'figma_rest' | 'reference_screens' | 'restore';
  createdBy?: number;
  parentVersion?: number | null;
  note?: string;
  format?: string;
  filename?: string;
  fileName?: string;
  fileKey?: string;
  restoredFrom?: number;
  warnings?: string[];
}

export interface DesignSystem {
  id: number;
  project_id: number;
  version: number;
  tokens: Tokens;
  assets: (Attachment & { role: string })[];
  source: DesignSystemSource;
  created_at: string;
}

export type DesignSystemVersion = Omit<DesignSystem, 'tokens'> & { counts: Record<keyof Tokens, number> };

export type Layout = 'split' | 'full-bleed' | 'centered-type' | 'asymmetric-collage' | 'editorial-grid' | 'framed-card';

export interface ArtDirection {
  id: number;
  key: 'A' | 'B' | 'C' | 'D';
  title: string;
  concept: string;
  mood: string[];
  composition: { layout: Layout; focalPoint: string; textAlign: 'left' | 'center' | 'right'; imageTreatment: string; description: string };
  palette: { name: string; value: string; role: string }[];
  typography: string;
  imagery: string;
  copy: { headline: string; subheadline: string; cta: string };
  requiredContent: { item: string; placement: string }[];
  hardConstraints: { constraint: string; how: string }[];
  softConstraints: { constraint: string; applied: boolean; note: string }[];
}

export interface ArtDirectionRun {
  runId: string;
  source: 'llm' | 'fallback';
  fallbackReason: { code: string; message: string; errors?: Record<string, string> } | null;
  provider: string;
  model: string;
  cost: number | null;
  prompt: string;
  created_at: string;
  interpretation: { summary: string; audience: string; keyMessages: string[] };
  context: { designSystem: { id: number; version: number } | null; requiredContent: string[]; hardConstraints: string[] };
  directions: ArtDirection[];
}

export type ImageStatus = 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled';

export interface ImageItem {
  id: number;
  parent_id: number;
  directionKey: string;
  title: string;
  status: ImageStatus;
  statusLabel: string;
  error: { code: string; message: string } | null;
  lastError: { code: string; message: string } | null;
  cost: number | null;
  costSource: 'estimate' | 'provider';
  prompt: string;
  remixOf: number | null;
  /** 'low' = szkic z dashboardu (do dopracowania), 'medium' = dopracowany. */
  quality?: 'low' | 'medium' | 'high' | null;
  refineOf?: number | null;
  /** Tylko w kliencie: podgląd szkicu pokazywany podczas dopracowania. */
  preview?: string;
  remixPrompt: string | null;
  version: number;
  rootId: number;
  sources: ImageSource[];
  created_at: string;
  image: { id: number; url: string; width: number | null; height: number | null } | null;
}

export interface ImageSource {
  inspirationId: number;
  role: string;
  title: string;
  creator: string;
  sourceUrl: string;
  thumbnailUrl: string;
  license: string | null;
  available?: boolean;
}

export interface GalleryItem extends ImageItem {
  batchId: string;
  mock: boolean;
  provider: string;
  model: string;
}

export interface Gallery {
  selectedId: number | null;
  items: GalleryItem[];
}

export interface Lineage {
  id: number;
  rootId: number;
  path: number[];
  versions: GalleryItem[];
  direction: (ArtDirection & { id: number }) | null;
}

export interface ImageBatch {
  batchId: string;
  artRunId: string | null;
  provider: string;
  model: string;
  mock: boolean;
  status: ImageStatus | 'partial';
  cost: number | null;
  created_at: string;
  items: ImageItem[];
}

export interface ImageStatusInfo {
  configured: boolean;
  provider: string | null;
  model: string | null;
  mock: boolean;
  unitCost: number | null;
  maxVariants: number;
  maxBatchCost: number;
  dailyBudget: number;
}

export interface License { code: string; version: string; name: string; url: string }

export interface SearchItem {
  token: string;
  providerId: string;
  title: string;
  sourceUrl: string;
  thumbnailUrl: string;
  creator: string;
  creatorUrl: string;
  sourceName: string;
  indexedAt: string;
  width: number | null;
  height: number | null;
  license: License | null;
  saved?: boolean;
}

export interface SearchResult {
  provider: string;
  providerLabel: string;
  mock: boolean;
  query: string;
  /** Zapytanie faktycznie wysłane do źródła (po tłumaczeniu na angielski). */
  searchedQuery?: string;
  page: number;
  perPage: number;
  total: number;
  items: SearchItem[];
}

export interface Inspiration {
  id: number;
  project_id: number;
  section_id: number | null;
  source_url: string;
  thumbnail_url: string;
  attribution: { title: string; creator: string; creatorUrl: string; sourceName: string; provider: string };
  rights: { license: License | null; declaredBy: string | null };
  metadata: { origin: 'search' | 'manual'; query?: string; indexedAt?: string; note?: string };
  created_at: string;
}

export interface ApiError {
  code: string;
  message: string;
  data?: { status?: number; errors?: Record<string, string> };
}

export const ATTACHMENT_TYPES = '.jpg,.jpeg,.png,.webp,.pdf';

// Nonce (X-WP-Nonce) i root REST ustawia skrypt wp-api-fetch drukowany przez Panel.
function path(route: string): string {
  return `/${window.claraConfig.restNamespace}/${route.replace(/^\//, '')}`;
}

export function claraFetch<T>(route: string, method = 'GET', data?: unknown): Promise<T> {
  return apiFetch<T>({ path: path(route), method, data });
}

export const getStatus = () => claraFetch<ClaraStatus>('status');
export const listProjects = () => claraFetch<Project[]>('projects?per_page=50');
export const getProject = (id: number) => claraFetch<Project>(`projects/${id}`);
export const createProject = (data: ProjectInput) => claraFetch<Project>('projects', 'POST', data);
export const updateProject = (id: number, data: ProjectInput) => claraFetch<Project>(`projects/${id}`, 'PATCH', data);
export const deleteProject = (id: number) => claraFetch<{ deleted: boolean }>(`projects/${id}`, 'DELETE');
export const listSectionTypes = () => claraFetch<{ value: string; label: string }[]>('section-types');
export const createSection = (projectId: number, data: SectionInput) => claraFetch<Section>(`projects/${projectId}/sections`, 'POST', data);
export const updateSection = (id: number, data: SectionInput) => claraFetch<Section>(`sections/${id}`, 'PATCH', data);
export const deleteSection = (id: number) => claraFetch<{ deleted: boolean }>(`sections/${id}`, 'DELETE');
export const deleteAttachment = (projectId: number, id: number) => claraFetch<{ deleted: boolean }>(`projects/${projectId}/attachments/${id}`, 'DELETE');

const ds = (projectId: number, rest = '') => `projects/${projectId}/design-systems${rest}`;
export const getLatestDesignSystem = (projectId: number) => claraFetch<DesignSystem | null>(ds(projectId, '/latest'));
export const listDesignSystemVersions = (projectId: number) => claraFetch<DesignSystemVersion[]>(ds(projectId));
export const saveDesignSystem = (projectId: number, tokens: Tokens, note = '') => claraFetch<DesignSystem>(ds(projectId), 'POST', { tokens, note });
export const importDesignSystem = (projectId: number, content: string, filename: string) => claraFetch<DesignSystem>(ds(projectId, '/import'), 'POST', { content, filename });
export const ingestFigma = (projectId: number) => claraFetch<DesignSystem>(ds(projectId, '/figma'), 'POST');
export const removeScreen = (projectId: number, assetId: number) => claraFetch<DesignSystem>(ds(projectId, `/screens/${assetId}`), 'DELETE');
export const restoreDesignSystem = (id: number) => claraFetch<DesignSystem>(`design-systems/${id}/restore`, 'POST');
export const getFigmaStatus = () => claraFetch<{ configured: boolean; capabilities: { styles: boolean; variables: boolean }; note: string }>('figma/status');

export const listArtDirections = (sectionId: number) => claraFetch<ArtDirectionRun[]>(`sections/${sectionId}/art-directions`);
export const generateArtDirections = (sectionId: number, prompt: string) => claraFetch<ArtDirectionRun>(`sections/${sectionId}/art-directions`, 'POST', { prompt });
export const getLlmStatus = () => claraFetch<{ configured: boolean; provider: string | null; model: string | null; layouts: Record<Layout, string> }>('llm/status');

export const listImageBatches = (sectionId: number) => claraFetch<ImageBatch[]>(`sections/${sectionId}/images`);
export const generateImages = (sectionId: number, runId: string, keys: string[] = []) => claraFetch<ImageBatch>(`sections/${sectionId}/images`, 'POST', { runId, keys });
export const cancelImageBatch = (sectionId: number, batchId: string) => claraFetch<ImageBatch>(`sections/${sectionId}/images/${batchId}/cancel`, 'POST');
export const getGallery = (sectionId: number) => claraFetch<Gallery>(`sections/${sectionId}/gallery`);
export const selectVariant = (sectionId: number, generationId: number | null) => claraFetch<{ selectedId: number | null }>(`sections/${sectionId}/selection`, 'POST', { generationId });
export const getLineage = (imageId: number) => claraFetch<Lineage>(`images/${imageId}/lineage`);
export const remixImage = (imageId: number, prompt: string, references: number[]) => claraFetch<ImageBatch>(`images/${imageId}/remix`, 'POST', { prompt, references });
export type LayoutNodeType = 'frame' | 'text' | 'image' | 'rectangle' | 'button';

export interface LayoutNode {
  id: string;
  type: LayoutNodeType;
  role?: string;
  name?: string;
  parentId: string | null;
  order: number;
  frame: { x: number; y: number; width: number; height: number };
  style: Partial<{ fill: string; color: string; opacity: number; radius: number; fontFamily: string; fontSize: number; fontWeight: number; lineHeight: number; letterSpacing: number; textAlign: 'left' | 'center' | 'right'; stroke: string; strokeWidth: number; padding: number; gap: number }>;
  content?: string;
  assetRef?: { generationId: number; crop?: { x: number; y: number; width: number; height: number } };
  layoutMode: 'none' | 'horizontal' | 'vertical';
  confidence: number;
  rasterized: boolean;
}

export interface LayoutJson {
  schemaVersion: number;
  canvas: { width: number; height: number; background?: string };
  nodes: LayoutNode[];
  tokens?: { colors?: { name: string; value: string }[] };
  rasterizedRegions: { nodeId: string; frame?: LayoutNode['frame']; reason: string }[];
  warnings: string[];
  source?: { generationId: number; assetId: number; analyzer: string; model: string };
}

export interface LayoutSummary { nodes: number; textNodes: number; rasterizedNodes: number; editableNodes: number; avgConfidence: number | null; lowConfidence: number }

export interface LayoutDocument {
  id: number;
  generation_id: number;
  schema_version: number;
  layout?: LayoutJson;
  validation: {
    valid: boolean;
    origin: 'analysis' | 'manual';
    analyzer: string;
    model?: string;
    cost?: number | null;
    basedOn?: number | null;
    fallbackReason?: { code: string; message: string } | null;
    warnings: string[];
    summary: LayoutSummary;
  };
  assets?: Record<string, { url: string; width: number | null; height: number | null }>;
  created_at: string;
}

export interface LayoutValidation { valid: boolean; errors: Record<string, string>; warnings: string[]; summary: LayoutSummary | [] }

export const listLayouts = (imageId: number) => claraFetch<LayoutDocument[]>(`images/${imageId}/layouts`);
export const getLayout = (id: number) => claraFetch<LayoutDocument>(`layouts/${id}`);
export const analyzeLayout = (imageId: number) => claraFetch<LayoutDocument>(`images/${imageId}/layouts`, 'POST', {});
export const saveLayout = (imageId: number, layout: unknown, basedOn?: number) => claraFetch<LayoutDocument>(`images/${imageId}/layouts`, 'POST', { layout, basedOn });
export const validateLayout = (layout: unknown) => claraFetch<LayoutValidation>('layouts/validate', 'POST', { layout });

export interface FigmaToken { token: string; expiresAt: string; ttl: number; endpoint: string }

export const getFigmaBundle = (layoutId: number) => claraFetch<Record<string, unknown>>(`layouts/${layoutId}/figma-bundle`);
export const createFigmaToken = (layoutId: number) => claraFetch<FigmaToken>(`layouts/${layoutId}/figma-token`, 'POST');

export type Accent = 'blue' | 'violet' | 'pink' | 'teal' | 'sunset';
export type Effects = 'full' | 'reduced';
export interface Preferences { accent: Accent; scale: number; effects: Effects; options?: { accents: Accent[]; scales: number[]; effects: Effects[] } }

export const getPreferences = () => claraFetch<Preferences>('preferences');
export const savePreferences = (data: Partial<Pick<Preferences, 'accent' | 'scale' | 'effects'>>) => claraFetch<Preferences>('preferences', 'PATCH', data);

export const IMAGES_UPDATED = 'clara:images-updated';

export const getImageStatus = () => claraFetch<ImageStatusInfo>('images/status');

export const searchInspirations = (q: string, page = 1, commercial = false, project?: number) =>
  claraFetch<SearchResult>(`search?${new URLSearchParams({ q, page: String(page), commercial: commercial ? '1' : '0', ...(project ? { project: String(project) } : {}) })}`);
export const listInspirations = (projectId: number) => claraFetch<Inspiration[]>(`projects/${projectId}/inspirations`);
export const saveInspiration = (projectId: number, data: { token?: string; url?: string; title?: string; note?: string; section_id?: number }) =>
  claraFetch<{ inspiration: Inspiration; duplicate: boolean }>(`projects/${projectId}/inspirations`, 'POST', data);
export const deleteInspiration = (id: number) => claraFetch<{ deleted: boolean }>(`inspirations/${id}`, 'DELETE');

export function uploadScreen(projectId: number, file: File): Promise<DesignSystem> {
  return uploadFile<DesignSystem>(ds(projectId, '/screens'), file);
}

export function uploadAttachment(projectId: number, file: File): Promise<Attachment> {
  return uploadFile<Attachment>(`projects/${projectId}/attachments`, file);
}

function uploadFile<T>(route: string, file: File): Promise<T> {
  return apiFetch<T>({
    path: path(route),
    method: 'POST',
    body: file,
    headers: {
      'Content-Type': 'application/octet-stream',
      'Content-Disposition': `attachment; filename*=UTF-8''${encodeURIComponent(file.name)}`,
    },
  });
}

export function errorMessage(error: unknown): string {
  if (error && typeof error === 'object' && 'message' in error) {
    return String((error as ApiError).message);
  }

  return 'Nieznany błąd.';
}

export function fieldErrors(error: unknown): Record<string, string> {
  return (error as ApiError | undefined)?.data?.errors ?? {};
}

export interface InspirationResult {
  project: { id: number; name: string };
  section: { id: number; name: string };
  provider: string;
  model: string;
  keepDirection: boolean;
  round: number;
  batch: ImageBatch;
}

export interface InspirationOptions { projectId?: number; keepDirection?: boolean; sectionId?: number; round?: number }
export const startInspirations = (prompt: string, options: InspirationOptions = {}) => claraFetch<InspirationResult>('generate', 'POST', { prompt, ...options });
export const renderInspiration = (id: number) => claraFetch<ImageItem>(`generate/${id}/render`, 'POST');
export const refineInspiration = (id: number) => claraFetch<ImageItem>(`generate/${id}/refine`, 'POST');
export const setProjectDirection = (projectId: number, generationId: number) => claraFetch<{ direction: ProjectDirection }>(`projects/${projectId}/direction`, 'PUT', { generationId });
export const clearProjectDirection = (projectId: number) => claraFetch<{ direction: null }>(`projects/${projectId}/direction`, 'DELETE');
