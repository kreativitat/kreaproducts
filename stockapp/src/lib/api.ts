/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

const DEFAULT_DATA_URL = '/custom/kreaproducts/stock_mobile.php';

declare global {
  interface Window {
    __KREAPRODUCTS_STOCK_BOOTSTRAP__?: SessionData | null;
    __KREAPRODUCTS_STOCK_DATA_URL__?: string;
    __KREAPRODUCTS_STOCK_LOGIN_ERROR__?: string;
  }
}

export class ApiError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

interface ApiEnvelope<T> {
  success: boolean;
  data: T;
  error?: { message?: string };
}

export interface SessionData {
  user: {
    id: number;
    login: string;
    fullname: string;
    email: string;
  };
  entity: number;
  entity_label: string;
  rights: {
    count: number;
    close: number;
    history: number;
  };
  module_info: {
    module: string;
    version: string;
    editor: string;
    license: string;
    support: string;
    website: string;
  };
  token: string;
}

export interface LoginMeta {
  default_entity: number;
  google_login_token: string;
  google_button_label: string;
  entities: Array<{
    id: number;
    label: string;
    google: {
      enabled: number;
      login_url: string;
      logo_url: string;
      background_color: string;
    };
  }>;
}

export interface Warehouse {
  id: number;
  ref: string;
  description: string;
}

export interface InventoryMutationWindow {
  active: number;
  start: number;
  end: number;
  start_time: string;
  end_time: string;
}

export interface OpenInventorySummary {
  id: number;
  ref: string;
  title: string;
  date_inventory: number;
  total_lines: number;
  counted_lines: number;
}

export interface InventorySummary {
 id: number;
 ref: string;
 title: string;
  status: number;
  category_id: number;
  category_label: string;
  warehouse_id: number;
  warehouse_ref: string;
  date_creation: number;
  date_inventory: number;
  total_lines: number;
 counted_lines: number;
}

export interface InventoryListData {
  history_enabled: number;
  mutation_window: InventoryMutationWindow;
  inventories: InventorySummary[];
}

export interface InventoryTemplate {
  id: number;
  label: string;
  full_label: string;
  product_count: number;
  open_inventory: OpenInventorySummary | null;
}

export interface TemplateData {
  root_category_id: number;
  default_warehouse_id: number;
  history_enabled: number;
  mutation_window: InventoryMutationWindow;
  blocking_open_inventory: OpenInventorySummary | null;
  warehouses: Warehouse[];
  templates: InventoryTemplate[];
}

export interface InventoryLine {
  id: number;
  product_id: number;
  ref: string;
  label: string;
  barcode: string;
  batch: string;
  batch_managed: number;
  counted: number;
  quantity: number | null;
  expected_quantity?: number;
  virtual_stock_at_business_close?: number;
}

export interface InventoryEmailNotification {
  enabled: number;
  sent: number;
  recipient?: string;
  error?: string;
}

export interface InventoryDetail {
  id: number;
  ref: string;
  title: string;
  status: number;
  category_id: number;
  category_label: string;
  warehouse_id: number;
  warehouse_ref: string;
  date_creation: number;
  date_inventory: number;
  max_value_date: number;
  mutation_window: InventoryMutationWindow;
  counts_expired: number;
  blocked_by_open_inventory: number;
  history_locked: number;
  counted_lines: number;
  total_lines: number;
  complete: number;
  editable: number;
  can_count: number;
  can_edit_value_date: number;
  can_close: number;
  can_delete: number;
  can_edit: number;
  can_reverse: number;
  correction_mode: number;
  managed: number;
  can_view_analysis: number;
  virtual_stock_snapshot_time: string;
  email_notification?: InventoryEmailNotification;
  lines: InventoryLine[];
}

type QueryValue = string | number | null | undefined;

function getDataUrl(): string {
  return window.__KREAPRODUCTS_STOCK_DATA_URL__ || DEFAULT_DATA_URL;
}

function parseResponse<T>(text: string, status: number): T {
  let payload: ApiEnvelope<T> | null = null;
  try {
    payload = JSON.parse(text) as ApiEnvelope<T>;
  } catch {
    const start = text.indexOf('{');
    const end = text.lastIndexOf('}');
    if (start >= 0 && end > start) {
      try {
        payload = JSON.parse(text.slice(start, end + 1)) as ApiEnvelope<T>;
      } catch {
        payload = null;
      }
    }
  }
  if (!payload || !payload.success) {
    const message = payload?.error?.message || `Pedido recusado (${status}).`;
    throw new ApiError(message.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(), status);
  }
  return payload.data;
}

async function request<T>(
  action: string,
  query: Record<string, QueryValue> = {},
  init: RequestInit = {}
): Promise<T> {
  const url = new URL(getDataUrl(), window.location.href);
  url.searchParams.set('kps_action', action);
  Object.entries(query).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') {
      url.searchParams.set(key, String(value));
    }
  });

  const response = await fetch(url.toString(), {
    credentials: 'same-origin',
    cache: 'no-store',
    ...init,
    headers: {
      Accept: 'application/json',
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...(window.__KREAPRODUCTS_STOCK_BOOTSTRAP__?.token
        ? { 'X-CSRF-Token': window.__KREAPRODUCTS_STOCK_BOOTSTRAP__.token }
        : {}),
      ...(init.headers || {})
    }
  });
  const text = await response.text();
  if (!response.ok) {
    return parseResponse<T>(text, response.status);
  }
  return parseResponse<T>(text, response.status);
}

function post<T>(action: string, payload: Record<string, unknown>): Promise<T> {
  return request<T>(action, {}, { method: 'POST', body: JSON.stringify(payload) });
}

export function getBootstrapSession(): SessionData | null {
  return window.__KREAPRODUCTS_STOCK_BOOTSTRAP__ || null;
}

export function getLoginError(): string {
  return window.__KREAPRODUCTS_STOCK_LOGIN_ERROR__ || '';
}

export function getLoginMeta(): Promise<LoginMeta> {
  return request<LoginMeta>('login_meta');
}

export function getTemplates(): Promise<TemplateData> {
  return request<TemplateData>('templates');
}

export function getInventory(id: number): Promise<InventoryDetail> {
  return request<InventoryDetail>('inventory', { id });
}

export function getInventories(): Promise<InventoryListData> {
  return request<InventoryListData>('inventories');
}

export function startInventory(categoryId: number, warehouseId: number): Promise<InventoryDetail> {
  return post<InventoryDetail>('start_inventory', {
    category_id: categoryId,
    warehouse_id: warehouseId
  });
}

export function saveCounts(
  inventoryId: number,
  counts: Array<{ line_id: number; quantity: number | null }>,
  valueDate?: string
): Promise<InventoryDetail> {
  return post<InventoryDetail>('save_counts', {
    inventory_id: inventoryId,
    counts,
    date_inventory: valueDate
  });
}

export function closeInventory(inventoryId: number, allowIncomplete = false): Promise<InventoryDetail> {
  return post<InventoryDetail>('close_inventory', {
    inventory_id: inventoryId,
    allow_incomplete: allowIncomplete
  });
}

export function editInventory(inventoryId: number): Promise<InventoryDetail> {
  return post<InventoryDetail>('edit_inventory', { inventory_id: inventoryId });
}

export function deleteInventory(inventoryId: number): Promise<{ deleted: number; inventory_id: number }> {
  return post<{ deleted: number; inventory_id: number }>('delete_inventory', { inventory_id: inventoryId });
}

export function logout(): Promise<{ logged_out: number }> {
  return post<{ logged_out: number }>('logout', {});
}
