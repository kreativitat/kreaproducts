/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

import {
  AlertCircle,
  Archive,
  ArrowLeft,
  Boxes,
  Check,
  CheckCircle2,
  ChevronRight,
  CloudOff,
  Loader2,
  LogOut,
  Minus,
  Package,
  Pencil,
  Plus,
  RefreshCw,
  Save,
  ScanLine,
  Search,
  ShieldCheck,
  Trash2,
  Warehouse,
  X
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ApiError,
  closeInventory,
  deleteInventory,
  editInventory,
  getBootstrapSession,
  getInventories,
  getInventory,
  getLoginError,
  getLoginMeta,
  getTemplates,
  InventoryDetail,
  InventoryLine,
  InventorySummary,
  InventoryTemplate,
  LoginMeta,
  logout,
  saveCounts,
  SessionData,
  startInventory,
  TemplateData
} from './lib/api';

const APP_LOGO_URL = '/custom/kreaproducts/img/kreaproducts-stock-logo.png';
const MODULE_ENTRY_URL = '/custom/kreaproducts/stock_mobile.php';
const INVENTORY_STATUS_RECORDED = 2;
const LOGIN_ERROR_MESSAGES: Record<string, string> = {
  ErrorModuleNotEnabled: 'KreaProducts Stock não está ativo para esta entidade.'
};

function getErrorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiError || error instanceof Error ? error.message : fallback;
}

function getLoginErrorMessage(): string {
  const loginError = getLoginError();
  return LOGIN_ERROR_MESSAGES[loginError] || loginError;
}

function formatQuantity(value: number): string {
  return Number.isInteger(value) ? String(value) : String(value).replace('.', ',');
}

function parseQuantity(value: string): number | null {
  const normalized = value.trim().replace(',', '.');
  if (normalized === '') {
    return null;
  }
  const parsed = Number.parseFloat(normalized);
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null;
}

function draftKey(inventoryId: number): string {
  return `kreaproducts:draft:${inventoryId}`;
}

interface LocalInventoryDraft {
  counts: Record<number, string>;
  valueDate: string;
}

function readLocalDraft(inventoryId: number): LocalInventoryDraft {
  try {
    const raw = window.localStorage.getItem(draftKey(inventoryId));
    if (!raw) {
      return { counts: {}, valueDate: '' };
    }
    const parsed = JSON.parse(raw) as Record<string, unknown>;
    const parsedCounts = parsed.counts && typeof parsed.counts === 'object'
      ? parsed.counts as Record<string, unknown>
      : parsed;
    const draft: Record<number, string> = {};
    Object.entries(parsedCounts).forEach(([lineId, value]) => {
      if (/^\d+$/.test(lineId) && typeof value === 'string') {
        draft[Number(lineId)] = value;
      }
    });
    return {
      counts: draft,
      valueDate: typeof parsed.valueDate === 'string' ? parsed.valueDate : ''
    };
  } catch {
    return { counts: {}, valueDate: '' };
  }
}

function writeLocalDraft(inventoryId: number, counts: Record<number, string>, valueDate: string): void {
  window.localStorage.setItem(draftKey(inventoryId), JSON.stringify({ counts, valueDate }));
}

function formatDate(value: number): string {
  if (!value) {
    return '—';
  }
  const timestamp = value < 100000000000 ? value * 1000 : value;
  return new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short' }).format(new Date(timestamp));
}

function formatDateInput(value: number): string {
  if (!value) {
    return '';
  }
  const timestamp = value < 100000000000 ? value * 1000 : value;
  const parts = new Intl.DateTimeFormat('en-GB', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  }).formatToParts(new Date(timestamp));
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
}

function buildCounts(inventory: InventoryDetail, includeLocalDraft = true): Record<number, string> {
  const serverCounts: Record<number, string> = {};
  inventory.lines.forEach((line) => {
    if (line.quantity !== null) {
      serverCounts[line.id] = formatQuantity(line.quantity);
    }
  });
  return includeLocalDraft ? { ...serverCounts, ...readLocalDraft(inventory.id).counts } : serverCounts;
}

function normalizeBarcode(value: string): string {
  return value.replace(/\s+/g, '').toLowerCase();
}

interface ScannerModalProps {
  lines: InventoryLine[];
  onClose: () => void;
  onMatch: (line: InventoryLine) => void;
}

function ScannerModal({ lines, onClose, onMatch }: ScannerModalProps) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const [message, setMessage] = useState('A iniciar câmara...');

  useEffect(() => {
    let stopped = false;
    let stopScanner: (() => void) | null = null;
    const start = async () => {
      try {
        const { BrowserMultiFormatReader } = await import('@zxing/browser');
        const reader = new BrowserMultiFormatReader();
        const controls = await reader.decodeFromVideoDevice(undefined, videoRef.current!, (result) => {
          if (!result || stopped) {
            return;
          }
          const scanned = normalizeBarcode(result.getText());
          const line = lines.find((item) => item.barcode && normalizeBarcode(item.barcode) === scanned);
          if (!line) {
            setMessage(`Código ${result.getText()} não encontrado neste inventário.`);
            return;
          }
          stopped = true;
          controls.stop();
          onMatch(line);
        });
        stopScanner = () => controls.stop();
        setMessage('Câmara ativa');
      } catch (error) {
        setMessage(getErrorMessage(error, 'Não foi possível abrir a câmara.'));
      }
    };

    void start();
    return () => {
      stopped = true;
      stopScanner?.();
    };
  }, [lines, onMatch]);

  return (
    <div className="modal-layer" role="dialog" aria-modal="true" aria-label="Leitor de código de barras">
      <button type="button" className="modal-backdrop" onClick={onClose} aria-label="Fechar leitor" />
      <section className="scanner-sheet">
        <header className="modal-header">
          <div>
            <p className="eyebrow">Leitura</p>
            <h2>Código de barras</h2>
          </div>
          <button type="button" className="icon-button" onClick={onClose} title="Fechar" aria-label="Fechar">
            <X size={20} />
          </button>
        </header>
        <div className="scanner-viewport">
          <video ref={videoRef} muted playsInline />
          <span className="scanner-target" aria-hidden="true" />
        </div>
        <p className="scanner-message">{message}</p>
      </section>
    </div>
  );
}

interface ConfirmModalProps {
  title: string;
  detail: string;
  confirmLabel: string;
  tone?: 'default' | 'danger';
  loading: boolean;
  onConfirm: () => void;
  onClose: () => void;
}

function ConfirmModal({ title, detail, confirmLabel, tone = 'default', loading, onConfirm, onClose }: ConfirmModalProps) {
  return (
    <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
      <button type="button" className="modal-backdrop" onClick={onClose} disabled={loading} aria-label="Cancelar" />
      <section className="confirm-sheet">
        <div className={`confirm-icon ${tone}`}>
          {tone === 'danger' ? <AlertCircle size={24} /> : <CheckCircle2 size={24} />}
        </div>
        <h2 id="confirm-title">{title}</h2>
        <p>{detail}</p>
        <div className="confirm-actions">
          <button type="button" className="button secondary" onClick={onClose} disabled={loading}>
            Cancelar
          </button>
          <button type="button" className={`button ${tone === 'danger' ? 'danger' : 'primary'}`} onClick={onConfirm} disabled={loading}>
            {loading ? <Loader2 className="spin" size={18} /> : <Check size={18} />}
            {loading ? 'A processar...' : confirmLabel}
          </button>
        </div>
      </section>
    </div>
  );
}

function App() {
  const initialSession = getBootstrapSession();
  const [session] = useState<SessionData | null>(initialSession);
  const [loginMeta, setLoginMeta] = useState<LoginMeta | null>(null);
  const [loginEntity, setLoginEntity] = useState(initialSession?.entity || 1);
  const [loginLoading, setLoginLoading] = useState(false);
  const [loading, setLoading] = useState(true);
  const [templates, setTemplates] = useState<TemplateData | null>(null);
  const [inventory, setInventory] = useState<InventoryDetail | null>(null);
  const [counts, setCounts] = useState<Record<number, string>>({});
  const [valueDate, setValueDate] = useState('');
  const [dirty, setDirty] = useState(false);
  const [search, setSearch] = useState('');
  const [error, setError] = useState(getLoginErrorMessage() || '');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [online, setOnline] = useState(window.navigator.onLine);
  const [startCandidate, setStartCandidate] = useState<InventoryTemplate | null>(null);
  const [deleteCandidate, setDeleteCandidate] = useState<InventoryDetail | null>(null);
  const [editCandidate, setEditCandidate] = useState<InventoryDetail | null>(null);
  const [showCloseConfirm, setShowCloseConfirm] = useState(false);
  const [showScanner, setShowScanner] = useState(false);
  const [highlightLine, setHighlightLine] = useState<number | null>(null);
  const [historyView, setHistoryView] = useState(false);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [inventoryList, setInventoryList] = useState<InventorySummary[]>([]);
  const closeInFlightRef = useRef(false);
  const feedbackTimerRef = useRef<number | null>(null);

  const clearFeedback = () => {
    setError('');
    setNotice('');
  };

  const showError = (message: string) => {
    setNotice('');
    setError(message);
  };

  const showNotice = (message: string) => {
    setError('');
    setNotice(message);
  };

  useEffect(() => {
    const handleOnline = () => setOnline(true);
    const handleOffline = () => setOnline(false);
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  useEffect(() => {
    if (feedbackTimerRef.current !== null) {
      window.clearTimeout(feedbackTimerRef.current);
      feedbackTimerRef.current = null;
    }
    if (!error && !notice) {
      return;
    }
    feedbackTimerRef.current = window.setTimeout(() => {
      clearFeedback();
      feedbackTimerRef.current = null;
    }, 5000);

    return () => {
      if (feedbackTimerRef.current !== null) {
        window.clearTimeout(feedbackTimerRef.current);
        feedbackTimerRef.current = null;
      }
    };
  }, [error, notice]);

  useEffect(() => {
    const bootstrap = async () => {
      try {
        if (session) {
          setTemplates(await getTemplates());
        } else {
          const meta = await getLoginMeta();
          setLoginMeta(meta);
          setLoginEntity(meta.default_entity || meta.entities[0]?.id || 1);
        }
      } catch (loadError) {
        showError(getErrorMessage(loadError, 'Não foi possível carregar KreaProducts Stock.'));
      } finally {
        setLoading(false);
      }
    };
    void bootstrap();
  }, [session]);

  useEffect(() => {
    if (!inventory || !dirty || inventory.editable !== 1) {
      return;
    }
    writeLocalDraft(inventory.id, counts, valueDate);
  }, [counts, dirty, inventory, valueDate]);

  useEffect(() => {
    if (!inventory) {
      setValueDate('');
      return;
    }
    const draft = readLocalDraft(inventory.id);
    setValueDate(draft.valueDate || formatDateInput(inventory.date_inventory));
  }, [inventory?.id, inventory?.date_inventory]);

  const selectedLoginEntity = loginMeta?.entities.find((entity) => entity.id === loginEntity) || null;
  const historyEnabled = true;
  const inventoryEditable = inventory?.editable === 1;
  const filteredLines = useMemo(() => {
    if (!inventory) {
      return [];
    }
    const query = search.trim().toLowerCase();
    if (!query) {
      return inventory.lines;
    }
    return inventory.lines.filter((line) =>
      [line.ref, line.label, line.barcode, line.batch].some((value) => value.toLowerCase().includes(query))
    );
  }, [inventory, search]);

  const localCounted = useMemo(() => {
    if (!inventory) {
      return 0;
    }
    return inventory.lines.filter((line) => parseQuantity(counts[line.id] ?? '') !== null).length;
  }, [counts, inventory]);

  const uncountedCount = inventory ? Math.max(0, inventory.total_lines - localCounted) : 0;

  const reloadTemplates = async () => {
    const nextTemplates = await getTemplates();
    setTemplates(nextTemplates);
  };

  const loadInventories = async () => {
    setHistoryLoading(true);
    clearFeedback();
    try {
      const history = await getInventories();
      setInventoryList(history.inventories);
      setHistoryView(true);
    } catch (loadError) {
      showError(getErrorMessage(loadError, 'Não foi possível carregar os inventários.'));
    } finally {
      setHistoryLoading(false);
    }
  };

  const openInventory = async (inventoryId: number, returnToHistory = false) => {
    setBusy(true);
    clearFeedback();
	  try {
		const detail = await getInventory(inventoryId);
		const includeDraft = detail.editable === 1;
		const draft = readLocalDraft(detail.id);
		setValueDate(draft.valueDate || formatDateInput(detail.date_inventory));
		setInventory(detail);
		setCounts(buildCounts(detail, includeDraft));
		setDirty(includeDraft && (Object.keys(draft.counts).length > 0 || draft.valueDate !== ''));
      setSearch('');
      setHistoryView(returnToHistory);
    } catch (loadError) {
      showError(getErrorMessage(loadError, 'Não foi possível abrir o inventário.'));
    } finally {
      setBusy(false);
    }
  };

  const handleTemplate = (template: InventoryTemplate) => {
    if (template.open_inventory) {
      void openInventory(template.open_inventory.id);
      return;
    }
    setStartCandidate(template);
  };

  const handleStart = async () => {
    if (!startCandidate || !templates) {
      return;
    }
    setBusy(true);
    clearFeedback();
    try {
      const detail = await startInventory(startCandidate.id, templates.default_warehouse_id);
      setInventory(detail);
      setCounts(buildCounts(detail, true));
      setDirty(false);
      setStartCandidate(null);
      await reloadTemplates();
    } catch (startError) {
      showError(getErrorMessage(startError, 'Não foi possível iniciar o inventário.'));
    } finally {
      setBusy(false);
    }
  };

  const updateCount = (lineId: number, value: string) => {
    if (!inventory || inventory.editable !== 1) {
      return;
    }
    const normalized = value.replace(/[^0-9.,]/g, '').replace(/([.,].*)[.,]/g, '$1');
    setCounts((current) => ({ ...current, [lineId]: normalized }));
    setDirty(true);
    showNotice(online ? 'Alterações por sincronizar' : 'Guardado neste dispositivo');
  };

  const stepCount = (lineId: number, delta: number) => {
    if (!inventory || inventory.editable !== 1) {
      return;
    }
    const current = parseQuantity(counts[lineId] ?? '') ?? 0;
    updateCount(lineId, formatQuantity(Math.max(0, current + delta)));
  };

  const persistCounts = async (
    options: {
      manageBusy?: boolean;
      reloadAfterSave?: boolean;
      showNotice?: boolean;
    } = {}
  ): Promise<InventoryDetail | null> => {
    const manageBusy = options.manageBusy ?? true;
    const reloadAfterSave = options.reloadAfterSave ?? true;
    const shouldNotify = options.showNotice ?? true;

    if (!inventory || inventory.editable !== 1) {
      return null;
    }
    if (!online) {
      writeLocalDraft(inventory.id, counts, valueDate);
      if (shouldNotify) {
        showNotice('Guardado neste dispositivo');
      }
      return null;
    }

    const payload = inventory.lines.map((line) => {
      const quantity = parseQuantity(counts[line.id] ?? '');
      return { line_id: line.id, quantity };
    });
    if (manageBusy) {
      setBusy(true);
    }
    clearFeedback();
    try {
      const detail = await saveCounts(
        inventory.id,
        payload,
        inventory.can_edit_value_date === 1 ? valueDate : undefined
      );
      setInventory(detail);
      setCounts(buildCounts(detail, detail.editable === 1));
      setDirty(false);
      window.localStorage.removeItem(draftKey(inventory.id));
      if (shouldNotify) {
        showNotice('Inventário sincronizado');
      }
      if (reloadAfterSave) {
        await reloadTemplates();
      }
      return detail;
    } catch (saveError) {
      writeLocalDraft(inventory.id, counts, valueDate);
      showError(getErrorMessage(saveError, 'Não foi possível guardar as contagens.'));
      return null;
    } finally {
      if (manageBusy) {
        setBusy(false);
      }
    }
  };

  const handleClose = async () => {
    if (!inventory || inventory.can_close !== 1 || !online || closeInFlightRef.current) {
      return;
    }
    closeInFlightRef.current = true;
    setBusy(true);
    clearFeedback();
    setShowCloseConfirm(false);
    try {
      if (dirty) {
        const saved = await persistCounts({ manageBusy: false, reloadAfterSave: false, showNotice: false });
        if (!saved) {
          return;
        }
      }
      const closedInventory = await closeInventory(inventory.id, uncountedCount > 0);
      window.localStorage.removeItem(draftKey(inventory.id));
      setShowCloseConfirm(false);
      setHistoryView(false);
      setInventory(null);
      setCounts({});
      setDirty(false);
      if (closedInventory.email_notification?.enabled === 1 && closedInventory.email_notification.sent === 1) {
        showNotice('Inventário concluído e enviado por email');
      } else {
        if (closedInventory.email_notification?.enabled === 1 && closedInventory.email_notification.sent !== 1) {
          showError(`Inventário concluído, mas o email automático falhou: ${closedInventory.email_notification.error || 'verifique a configuração de email.'}`);
        } else {
          showNotice('Inventário concluído');
        }
      }
      await reloadTemplates();
    } catch (closeError) {
      showError(getErrorMessage(closeError, 'Não foi possível concluir o inventário.'));
    } finally {
      closeInFlightRef.current = false;
      setBusy(false);
    }
  };

  const handleDeleteInventory = async () => {
    if (!deleteCandidate || deleteCandidate.can_delete !== 1 || busy) {
      return;
    }
    setBusy(true);
    clearFeedback();
    try {
      await deleteInventory(deleteCandidate.id);
      window.localStorage.removeItem(draftKey(deleteCandidate.id));
      if (inventory?.id === deleteCandidate.id) {
        setInventory(null);
        setCounts({});
        setDirty(false);
      }
      setDeleteCandidate(null);
      showNotice('Inventário eliminado');
      await reloadTemplates();
    } catch (deleteError) {
      showError(getErrorMessage(deleteError, 'Não foi possível eliminar o inventário.'));
    } finally {
      setBusy(false);
    }
  };

  const handleEditInventory = async () => {
    if (!editCandidate || editCandidate.can_edit !== 1 || busy) {
      return;
    }
    setBusy(true);
    clearFeedback();
    try {
      const editableInventory = await editInventory(editCandidate.id);
      setInventory(editableInventory);
      setCounts(buildCounts(editableInventory, false));
      setValueDate(formatDateInput(editableInventory.date_inventory));
      setDirty(false);
      setEditCandidate(null);
      showNotice('Inventário reaberto para edição');
      await reloadTemplates();
    } catch (editError) {
      showError(getErrorMessage(editError, 'Não foi possível editar o inventário.'));
    } finally {
      setBusy(false);
    }
  };

  const handleGoogleLogin = () => {
    const google = selectedLoginEntity?.google;
    if (!loginMeta || !google?.enabled || !google.login_url) {
      showError('O início de sessão Google não está disponível nesta entidade.');
      return;
    }
    setLoginLoading(true);
    const form = document.createElement('form');
    form.method = 'post';
    form.action = google.login_url;
    form.style.display = 'none';
    const append = (name: string, value: string) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      form.appendChild(input);
    };
    append('token', loginMeta.google_login_token);
    append('actionlogin', 'login');
    append('beforeoauthloginredirect', 'google');
    append('entity', String(loginEntity));
    append('backtopage', MODULE_ENTRY_URL);
    append('screenwidth', String(window.screen?.width || window.innerWidth));
    append('screenheight', String(window.screen?.height || window.innerHeight));
    append('tz_string', Intl.DateTimeFormat().resolvedOptions().timeZone || '');
    document.body.appendChild(form);
    form.submit();
  };

  const handleLogout = async () => {
    setBusy(true);
    try {
      await logout();
    } finally {
      window.location.replace(MODULE_ENTRY_URL);
    }
  };

  const handleScanMatch = (line: InventoryLine) => {
    setShowScanner(false);
    setSearch(line.ref);
    setHighlightLine(line.id);
    window.setTimeout(() => {
      document.getElementById(`line-${line.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(() => setHighlightLine(null), 1600);
    }, 80);
  };

  if (loading) {
    return (
      <main className="loading-screen">
        <img src={APP_LOGO_URL} alt="KreaProducts Stock" />
        <Loader2 className="spin" size={24} />
      </main>
    );
  }

  if (!session) {
    const google = selectedLoginEntity?.google;
    const loginLogo = google?.logo_url || APP_LOGO_URL;
    const unavailableMessage = loginMeta && loginMeta.entities.length === 0
      ? 'KreaProducts Stock não está ativo em nenhuma entidade.'
      : 'Google não está configurado para esta entidade.';
    return (
      <main className="auth-screen">
        <section className="auth-panel" style={{ '--entity-accent': google?.background_color || '#087f72' } as React.CSSProperties}>
          <div className="auth-brand">
            <img src={loginLogo} alt="KreaProducts Stock" className="auth-logo" />
            <div>
              <p className="eyebrow">Inventários</p>
              <h1>KreaProducts Stock</h1>
              <p>Contagens simples. Stock rigoroso.</p>
            </div>
          </div>

          {loginMeta && loginMeta.entities.length > 1 ? (
            <label className="field-label">
              Entidade
              <select value={loginEntity} onChange={(event) => setLoginEntity(Number(event.target.value))} disabled={loginLoading}>
                {loginMeta.entities.map((entity) => (
                  <option value={entity.id} key={entity.id}>{entity.label}</option>
                ))}
              </select>
            </label>
          ) : selectedLoginEntity ? (
            <div className="entity-row"><Warehouse size={17} />{selectedLoginEntity.label}</div>
          ) : null}

          <button
            type="button"
            className="google-button"
            onClick={handleGoogleLogin}
            disabled={loginLoading || !google?.enabled}
          >
            {loginLoading ? <Loader2 className="spin" size={20} /> : <span className="google-g" aria-hidden="true">G</span>}
            {loginLoading ? 'A redirecionar...' : loginMeta?.google_button_label || 'Entrar com Google'}
          </button>
          {!google?.enabled && <p className="auth-unavailable">{unavailableMessage}</p>}
          {error && <p className="error-message"><AlertCircle size={17} />{error}</p>}
          <div className="auth-security"><ShieldCheck size={16} />Autenticação protegida por Krea2FactorAuth</div>
        </section>
      </main>
    );
  }

  return (
    <main className="app-shell">
      <header className="app-header">
        <div className="brand-lockup">
          <img src={APP_LOGO_URL} alt="" />
          <div>
            <p className="eyebrow">{inventory ? inventory.warehouse_ref : historyView ? 'Histórico' : 'Inventários'}</p>
            <h1>{inventory ? inventory.category_label : historyView ? 'Inventários' : 'KreaProducts Stock'}</h1>
          </div>
        </div>
        <div className="header-actions">
          {!online && <span className="offline-badge"><CloudOff size={15} />Offline</span>}
          <button type="button" className="icon-button" onClick={() => void handleLogout()} disabled={busy} title="Sair" aria-label="Sair">
            <LogOut size={20} />
          </button>
        </div>
      </header>

      {(error || notice) && (
        <div className={`feedback ${error ? 'error' : 'success'}`}>
          {error ? <AlertCircle size={18} /> : <CheckCircle2 size={18} />}
          {error || notice}
          <button type="button" onClick={clearFeedback} title="Fechar"><X size={16} /></button>
        </div>
      )}

      {!inventory && historyView ? (
        <section className="history-view">
		  <div className="section-heading">
            <div className="section-heading-left">
              <button type="button" className="icon-button" onClick={() => setHistoryView(false)} title="Voltar" aria-label="Voltar">
                <ArrowLeft size={21} />
              </button>
              <div>
                <p className="eyebrow">{inventoryList.length} registos</p>
                <h2>Inventários</h2>
              </div>
            </div>
            <button type="button" className="icon-button" onClick={() => void loadInventories()} disabled={historyLoading} title="Atualizar" aria-label="Atualizar">
              <RefreshCw className={historyLoading ? 'spin' : ''} size={19} />
            </button>
          </div>

          <div className="history-list">
            {inventoryList.map((item) => (
              <button type="button" className="history-card" key={item.id} onClick={() => void openInventory(item.id, true)} disabled={busy}>
                <span className="template-icon history-icon">{item.status === INVENTORY_STATUS_RECORDED ? <Archive size={23} /> : <Package size={23} />}</span>
                <span className="template-copy">
                  <strong>{item.category_label}</strong>
                  <span>{item.warehouse_ref} · {formatDate(item.date_inventory || item.date_creation)}</span>
                  <span>{item.ref}</span>
                </span>
                <span className="template-status">
                  <b>{item.counted_lines} de {item.total_lines}</b>
                  <small>{item.status === INVENTORY_STATUS_RECORDED ? 'Abrir' : 'Continuar'}</small>
                </span>
              </button>
            ))}
          </div>
          {inventoryList.length === 0 && <div className="empty-state"><Archive size={34} /><p>Sem inventários.</p></div>}
        </section>
      ) : !inventory ? (
        <section className="home-view">
          <div className="section-heading">
            <div>
              <p className="eyebrow">{templates?.warehouses.find((warehouse) => warehouse.id === templates.default_warehouse_id)?.ref || session.entity_label}</p>
              <h2>Inventários por categoria</h2>
            </div>
            <button type="button" className="icon-button" onClick={() => void reloadTemplates()} disabled={busy} title="Atualizar" aria-label="Atualizar">
              <RefreshCw className={busy ? 'spin' : ''} size={19} />
            </button>
		  </div>
		  {templates?.mutation_window.active === 1 && (
			<div className="correction-warning">
			  <AlertCircle size={18} />
			  <span>Inventários apenas para consulta entre as {templates.mutation_window.start_time} e as {templates.mutation_window.end_time}.</span>
			</div>
		  )}

		  <div className="template-grid">
            {templates?.templates.map((template, index) => {
              const open = template.open_inventory;
              return (
				<button type="button" className={`template-card accent-${index % 4}`} key={template.id} onClick={() => handleTemplate(template)} disabled={busy || session.rights.count !== 1 || templates.mutation_window.active === 1}>
                  <span className="template-icon"><Package size={24} /></span>
                  <span className="template-copy">
                    <strong>{template.label}</strong>
                    <span>{template.product_count} produtos</span>
                  </span>
                  {open ? (
                    <span className="template-status">
                      <b>{open.counted_lines} de {open.total_lines}</b>
                      <small>Continuar</small>
                    </span>
                  ) : (
                    <ChevronRight size={20} />
                  )}
                </button>
              );
            })}
          </div>
          {templates?.templates.length === 0 && <div className="empty-state"><Boxes size={34} /><p>Sem inventários configurados.</p></div>}
          {historyEnabled && (
            <footer className="home-footer">
              <button type="button" className="button secondary" onClick={() => void loadInventories()} disabled={historyLoading}>
                {historyLoading ? <Loader2 className="spin" size={18} /> : <Archive size={18} />}
                Inventários
              </button>
            </footer>
          )}
        </section>
      ) : (
        <section className="inventory-view">
          <div className="inventory-toolbar">
            <button type="button" className="icon-button" onClick={() => setInventory(null)} title="Voltar" aria-label="Voltar">
              <ArrowLeft size={21} />
            </button>
            <div className="inventory-count-status">
              <span>{inventory.category_label}</span>
			  <strong>{inventory.correction_mode === 1
				? `Correção do dia · ${localCounted} de ${inventory.total_lines}`
				: inventoryEditable
					? `${localCounted} de ${inventory.total_lines} contados`
					: `Concluído · ${localCounted} de ${inventory.total_lines}`}</strong>
			  {!inventoryEditable && <em className="read-only-badge">Apenas leitura</em>}
            </div>
          </div>

		  {inventory.correction_mode === 1 && (
            <div className="correction-warning">
              <AlertCircle size={18} />
              <span>Este inventário é do dia {formatDate(inventory.date_inventory)}. Só pode corrigir as contagens referentes a esse dia.</span>
            </div>
		  )}

		  {inventory.mutation_window.active === 1 && (
			<div className="correction-warning">
			  <AlertCircle size={18} />
			  <span>Inventário apenas para consulta entre as {inventory.mutation_window.start_time} e as {inventory.mutation_window.end_time}.</span>
			</div>
		  )}

		  {inventory.counts_expired === 1 && (
			<div className="correction-warning">
			  <AlertCircle size={18} />
			  <span>Esta contagem expirou porque podem existir movimentos de stock posteriores. Elimine este inventário, crie o inventário atual e conte novamente os produtos.</span>
			</div>
		  )}

		  {inventory.blocked_by_open_inventory === 1 && (
			<div className="correction-warning">
			  <AlertCircle size={18} />
			  <span>Existe um inventário anterior aberto para esta categoria e armazém. Feche-o ou elimine-o antes de guardar ou executar este inventário; até lá, este duplicado só pode ser eliminado.</span>
			</div>
		  )}

		  {inventory.history_locked === 1 && (
			<div className="correction-warning">
			  <AlertCircle size={18} />
			  <span>Este inventário pertence a uma janela de contagem encerrada e é permanentemente apenas para consulta.</span>
			</div>
		  )}

          <label className="field-label value-date-field">
            Data valor
            <input
              type="date"
              value={valueDate}
              max={formatDateInput(inventory.max_value_date)}
              onChange={(event) => {
                setValueDate(event.target.value);
                setDirty(true);
              }}
              disabled={inventory.can_edit_value_date !== 1 || busy}
            />
          </label>

          <div className="count-controls">
            <label className="search-field">
              <Search size={18} />
              <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Produto ou referência" />
            </label>
            <button type="button" className="icon-button scan-button" onClick={() => setShowScanner(true)} disabled={!inventoryEditable} title="Ler código" aria-label="Ler código">
              <ScanLine size={21} />
            </button>
          </div>

          {inventoryEditable ? (
            <div className="sync-row">
              <span className={dirty ? 'dirty' : 'synced'}>{dirty ? (online ? 'Por sincronizar' : 'Guardado no dispositivo') : 'Sincronizado'}</span>
              <button type="button" className="button secondary compact" onClick={() => void persistCounts()} disabled={busy || !dirty}>
                {busy ? <Loader2 className="spin" size={17} /> : <Save size={17} />}
                Guardar
              </button>
            </div>
          ) : (
            <div className="read-only-row">
              <Archive size={16} />
              Inventário concluído em modo apenas leitura
            </div>
          )}

          <div className="count-list">
            {filteredLines.map((line) => {
              const value = counts[line.id] ?? '';
              const counted = parseQuantity(value) !== null;
              return (
                <article id={`line-${line.id}`} className={`count-row ${counted ? 'counted' : ''} ${highlightLine === line.id ? 'highlight' : ''}`} key={line.id}>
                  <div className="count-product">
                    <span className="count-status">{counted ? <Check size={16} /> : <Package size={16} />}</span>
                    <div>
                      <strong>{line.label}</strong>
                      <span>{line.ref}{line.batch ? ` · ${line.batch}` : ''}</span>
					  {inventory.can_view_analysis === 1 && typeof line.virtual_stock_at_business_close === 'number' && (
						<span>Stock virtual às {inventory.virtual_stock_snapshot_time}: {formatQuantity(line.virtual_stock_at_business_close)}</span>
					  )}
                    </div>
                  </div>
                  <div className="quantity-control">
                    <button type="button" onClick={() => stepCount(line.id, -1)} disabled={!inventoryEditable} title="Diminuir" aria-label={`Diminuir ${line.label}`}>
                      <Minus size={18} />
                    </button>
                    <input
                      type="text"
                      inputMode="decimal"
                      value={value}
                      onChange={(event) => updateCount(line.id, event.target.value)}
                      placeholder="—"
                      disabled={!inventoryEditable}
                      aria-label={`Quantidade de ${line.label}`}
                    />
                    <button type="button" onClick={() => stepCount(line.id, 1)} disabled={!inventoryEditable} title="Aumentar" aria-label={`Aumentar ${line.label}`}>
                      <Plus size={18} />
                    </button>
                  </div>
                </article>
              );
            })}
          </div>

          {filteredLines.length === 0 && <div className="empty-state"><Search size={30} /><p>Nenhum produto encontrado.</p></div>}

          {inventoryEditable && (
            <footer className="inventory-footer">
              <button type="button" className="button primary" onClick={() => void persistCounts()} disabled={busy || !dirty}>
                <Save size={18} />{inventory.correction_mode === 1 ? 'Guardar correções' : 'Guardar'}
              </button>
              {inventory.can_delete === 1 && (
                <button type="button" className="button danger" onClick={() => setDeleteCandidate(inventory)} disabled={busy}>
                  <Trash2 size={18} />Eliminar inventário
                </button>
              )}
              {inventory.can_close === 1 && (
                <button type="button" className="button primary" onClick={() => setShowCloseConfirm(true)} disabled={busy || !online}>
                  <CheckCircle2 size={18} />Concluir inventário
                </button>
              )}
            </footer>
          )}
          {!inventoryEditable && inventory.can_delete === 1 && (
            <footer className="inventory-footer">
              {inventory.can_edit === 1 && (
                <button type="button" className="button secondary" onClick={() => setEditCandidate(inventory)} disabled={busy || !online}>
                  <Pencil size={18} />Editar inventário
                </button>
              )}
              <button type="button" className="button danger" onClick={() => setDeleteCandidate(inventory)} disabled={busy}>
                <Trash2 size={18} />Eliminar inventário
              </button>
            </footer>
          )}
        </section>
      )}

      {startCandidate && (
        <ConfirmModal
          title={`Iniciar inventário: ${startCandidate.label}`}
          detail={`Apenas esta categoria · ${startCandidate.product_count} produtos · ${templates?.warehouses.find((warehouse) => warehouse.id === templates.default_warehouse_id)?.ref || 'Armazém'}`}
          confirmLabel="Iniciar"
          loading={busy}
          onConfirm={() => void handleStart()}
          onClose={() => setStartCandidate(null)}
        />
      )}
      {showCloseConfirm && inventory && (
        <ConfirmModal
          title="Concluir inventário"
          detail={uncountedCount > 0
            ? `${uncountedCount} produto${uncountedCount === 1 ? '' : 's'} sem valor. O stock ${uncountedCount === 1 ? 'deste produto não será alterado' : 'destes produtos não será alterado'}. Tem a certeza de que pretende continuar?`
            : 'Esta operação gera os movimentos de stock e fecha definitivamente a contagem.'}
          confirmLabel="Gerar movimentos"
          tone="danger"
          loading={busy}
          onConfirm={() => void handleClose()}
          onClose={() => setShowCloseConfirm(false)}
        />
      )}
      {deleteCandidate && (
        <ConfirmModal
          title="Eliminar inventário"
          detail={deleteCandidate.status === 2
			? 'Esta operação cria os movimentos compensatórios necessários e elimina o inventário fechado.'
			: 'Esta operação apaga a contagem iniciada e não gera movimentos de stock.'}
          confirmLabel="Eliminar"
          tone="danger"
          loading={busy}
          onConfirm={() => void handleDeleteInventory()}
          onClose={() => setDeleteCandidate(null)}
        />
      )}
      {editCandidate && (
        <ConfirmModal
          title="Editar inventário"
          detail="Os movimentos atuais serão compensados e o inventário regressará ao estado iniciado. Depois das alterações, execute novamente os movimentos para o fechar."
          confirmLabel="Editar"
          tone="danger"
          loading={busy}
          onConfirm={() => void handleEditInventory()}
          onClose={() => setEditCandidate(null)}
        />
      )}
      {showScanner && inventory && (
        <ScannerModal lines={inventory.lines} onMatch={handleScanMatch} onClose={() => setShowScanner(false)} />
      )}
    </main>
  );
}

export default App;
