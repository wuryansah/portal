export interface Billing {
  id: number;
  user_id: number;
  unit_number?: string;
  invoice_no: string;
  amount: number;
  paid: number;
  status?: 'pending' | 'paid' | 'overdue' | string;
  due_date: string;
  issued_date?: string;
  billing_period?: string;
  description?: string;
  category?: string;
  virtual_account?: string;
  other_rev?: number;
  electricity?: number;
  water?: number;
  gas?: number;
  sinking_fund?: number;
  fine?: number;
  rent?: number;
  service_charge?: number;
}

export interface WaRecipient {
  name: string;
  phone: string;
  unit_number?: string;
  email?: string;
}

export interface WaBillingMessage {
  template: string;
  billing: Billing;
  recipient: WaRecipient;
  language?: 'id' | 'en';
  useBreakdown?: boolean;
  currency?: string;
}

export type WaApiProvider =
  | 'whatsapp_cloud'
  | 'fonnte'
  | 'wablas'
  | 'twilio'
  | 'meta'
  | (string & {});

/* ===== Generic request/response models ===== */

export interface SendBillingViaWaRequest {
  recipient: WaRecipient;
  billing: Billing | number;
  message?: WaBillingMessage;
  redirect_to_wa?: boolean;
}

export interface SendBillingViaWaResponse {
  success: boolean;
  wa_link?: string;
  message?: string;
  error?: string;
}

export interface SendBillingViaWaApiRequest {
  provider: WaApiProvider;
  api_token?: string;
  from?: string;
  recipient: WaRecipient;
  billing: Billing | number;
  message?: WaBillingMessage;
  attach_pdf?: boolean;
  pdf_url?: string;
  scheduled_at?: string | null;
}

export interface SendBillingViaWaApiResponse {
  success: boolean;
  provider: WaApiProvider;
  message_id?: string;
  cost?: number;
  balance?: number;
  raw?: unknown;
  error?: string;
}

export interface SendBillingViaWaApiCallback {
  message_id?: string;
  status?: 'sent' | 'delivered' | 'read' | 'failed';
  timestamp?: string;
  error?: string;
}

/* ===== Provider-specific payloads ===== */

export interface WhatsAppCloudPayload {
  messaging_product: 'whatsapp';
  to: string;
  type: 'text' | 'template' | 'document';
  text?: { preview_url?: boolean; body: string };
  template?: {
    name: string;
    language: { code: string };
    components?: Array<{
      type: 'body' | 'header' | 'button';
      parameters?: Array<{ type: 'text'; text: string }>;
    }>;
  };
  document?: { link: string; filename?: string; caption?: string };
}

export interface WhatsAppCloudResponse {
  messaging_product: 'whatsapp';
  contacts: Array<{ input: string; wa_id: string }>;
  messages: Array<{ id: string }>;
  error?: {
    code: number;
    message: string;
    details?: string;
    error_subcode?: number;
  };
}

export interface FonntePayload {
  target: string;
  message: string;
  countryCode?: string;
  schedule?: string;
  url?: string;
  filename?: string;
}

export interface FonnteResponse {
  status?: boolean;
  id?: string;
  name?: string;
  reason?: string;
}

export interface WablasPayload {
  phone: string;
  message: string;
  document?: string;
  caption?: string;
  scheduledAt?: string;
}

export interface WablasResponse {
  status?: boolean;
  data?: {
    id?: string;
    status?: string;
    reason?: string;
  };
}

export interface TwilioPayload {
  To: string;
  From: string;
  Body?: string;
  MediaUrl?: string;
  ScheduleType?: string;
  SendAt?: string;
}

export interface TwilioResponse {
  sid: string;
  status?: string;
  error_code?: number;
  error_message?: string;
  date_created?: string;
}

export type WaApiPayload =
  | { provider: 'whatsapp_cloud'; payload: WhatsAppCloudPayload; endpoint: string }
  | { provider: 'fonnte'; payload: FonntePayload; endpoint: string }
  | { provider: 'wablas'; payload: WablasPayload; endpoint: string }
  | { provider: 'twilio'; payload: TwilioPayload; endpoint: string };

/* ===== Builders ===== */

export function normalizeWaPhone(phone: string, countryCode = '62'): string {
  let p = phone.replace(/[^\d+]/g, '');
  if (p.startsWith('+')) return p.slice(1);
  if (p.startsWith('0')) return countryCode + p.slice(1);
  return p;
}

export function formatWaMoney(amount: number, currency = 'Rp'): string {
  const formatted = new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 2,
  }).format(amount);
  return `${currency} ${formatted}`;
}

export function buildWaBillingMessage(msg: WaBillingMessage): string {
  const { billing, recipient, useBreakdown = false, currency = 'Rp' } = msg;
  const due = billing.due_date || '-';
  const va = billing.virtual_account || '-';
  let text = msg.template
    .replace('{name}', recipient.name)
    .replace('{unit_number}', recipient.unit_number || billing.unit_number || '-')
    .replace('{invoice_no}', billing.invoice_no)
    .replace('{amount}', formatWaMoney(billing.amount, currency))
    .replace('{due_date}', due)
    .replace('{virtual_account}', va);

  if (useBreakdown) {
    const items = [
      ['Rent', billing.rent],
      ['Service Charge', billing.service_charge],
      ['Electricity', billing.electricity],
      ['Water', billing.water],
      ['Gas', billing.gas],
      ['Sinking Fund', billing.sinking_fund],
      ['Other Revenue', billing.other_rev],
      ['Fine', billing.fine],
    ] as const;
    const lines = items
      .filter(([, v]) => v && v > 0)
      .map(([k, v]) => `${k}: ${formatWaMoney(v!, currency)}`)
      .join('\n');
    if (lines) text += `\n\nBreakdown:\n${lines}\nTotal: ${formatWaMoney(billing.amount, currency)}`;
  }
  return text;
}

export function buildWaLink(recipient: WaRecipient, message: string): string {
  return `https://wa.me/${normalizeWaPhone(recipient.phone)}?text=${encodeURIComponent(message)}`;
}

export function buildWaApiPayload(req: SendBillingViaWaApiRequest): WaApiPayload {
  const text = req.message ? buildWaBillingMessage(req.message) : '';
  const phone = normalizeWaPhone(req.recipient.phone);

  switch (req.provider) {
    case 'whatsapp_cloud':
    case 'meta':
      return {
        provider: 'whatsapp_cloud',
        endpoint: 'https://graph.facebook.com/v21.0',
        payload: {
          messaging_product: 'whatsapp',
          to: phone,
          type: req.attach_pdf && req.pdf_url ? 'document' : 'text',
          text: req.attach_pdf && req.pdf_url ? undefined : { preview_url: true, body: text },
          document:
            req.attach_pdf && req.pdf_url
              ? { link: req.pdf_url, filename: `${req.recipient.name}-billing.pdf`, caption: text }
              : undefined,
        },
      };
    case 'fonnte':
      return {
        provider: 'fonnte',
        endpoint: 'https://api.fonnte.com/send',
        payload: {
          target: phone,
          message: text,
          countryCode: '62',
          ...(req.scheduled_at ? { schedule: req.scheduled_at } : {}),
          ...(req.attach_pdf && req.pdf_url ? { url: req.pdf_url } : {}),
        },
      };
    case 'wablas':
      return {
        provider: 'wablas',
        endpoint: req.attach_pdf && req.pdf_url
          ? 'https://patp.wablas.com/api/send-document'
          : 'https://patp.wablas.com/api/send-message',
        payload: {
          phone,
          message: text,
          ...(req.attach_pdf && req.pdf_url
            ? { document: req.pdf_url, caption: text }
            : {}),
          ...(req.scheduled_at ? { scheduledAt: req.scheduled_at } : {}),
        },
      };
    case 'twilio':
      return {
        provider: 'twilio',
        endpoint: `https://api.twilio.com/2010-04-01/Accounts/${req.from ?? ''}/Messages.json`,
        payload: {
          To: `whatsapp:${phone}`,
          From: `whatsapp:${req.from ?? ''}`,
          Body: text,
          ...(req.attach_pdf && req.pdf_url ? { MediaUrl: req.pdf_url } : {}),
          ...(req.scheduled_at ? { ScheduleType: 'fixed', SendAt: req.scheduled_at } : {}),
        },
      };
    default:
      throw new Error(`Unsupported WhatsApp provider: ${req.provider}`);
  }
}
