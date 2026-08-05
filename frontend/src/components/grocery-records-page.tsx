"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import {
  Boxes,
  CalendarClock,
  ClipboardList,
  Download,
  Package,
  Pencil,
  Percent,
  Plus,
  Printer,
  ReceiptText,
  RefreshCcw,
  Scale,
  ShieldCheck,
  Store,
  Trash2,
  Truck,
} from "lucide-react";
import { ApiError, api, getApiErrorMessage } from "@/lib/api";
import { GroceryOptions, GroceryProduct, money, quantity } from "@/lib/grocery";
import {
  OperationField,
  OperationHeader,
  OperationModal,
  OperationNotice,
} from "@/components/operation-ui";
import { SearchableProductPicker } from "@/components/searchable-product-picker";
import { useAuth } from "@/lib/auth-context";

type Module =
  | "products"
  | "units"
  | "tax-rates"
  | "registers"
  | "promotions"
  | "accounts"
  | "sequences"
  | "cheques"
  | "customer-credit"
  | "inventory"
  | "expiry"
  | "reorder"
  | "sales"
  | "purchase-orders"
  | "goods-receipts"
  | "transfers"
  | "adjustments"
  | "stock-counts"
  | "shifts"
  | "expenses"
  | "supplier-payments"
  | "audit"
  | "reports"
  | "purchase-returns"
  | "sales-returns"
  | "cash";
type Row = Record<string, unknown>;

const inputClass =
  "w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100";

const CONFIG: Record<
  Module,
  {
    eyebrow: string;
    title: string;
    description: string;
    endpoint: string;
    icon: typeof Package;
    create?: string;
  }
> = {
  products: {
    eyebrow: "Catalogue",
    title: "Products",
    description:
      "Barcodes, selling units, price, tax, and grocery stock rules.",
    endpoint: "/v1/grocery/products",
    icon: Package,
    create: "Add product",
  },
  units: {
    eyebrow: "Catalogue setup",
    title: "Units of measure",
    description:
      "Base and alternate units used for purchase and sale conversions.",
    endpoint: "/v1/grocery/masters/units",
    icon: Scale,
    create: "Add unit",
  },
  "tax-rates": {
    eyebrow: "Tax setup",
    title: "Tax rates",
    description:
      "Configure inclusive or exclusive tax rates used by products and POS.",
    endpoint: "/v1/grocery/masters/tax-rates",
    icon: Percent,
    create: "Add tax rate",
  },
  registers: {
    eyebrow: "Counter setup",
    title: "Registers",
    description: "POS terminals and their stock locations.",
    endpoint: "/v1/grocery/masters/registers",
    icon: Store,
    create: "Add register",
  },
  promotions: {
    eyebrow: "Pricing",
    title: "Promotions",
    description: "Scheduled product, category, brand, and basket offers.",
    endpoint: "/v1/grocery/masters/promotions",
    icon: Percent,
    create: "Add promotion",
  },
  accounts: {
    eyebrow: "Accounting",
    title: "Chart of accounts",
    description: "Configure branch account codes used by advanced accounting.",
    endpoint: "/v1/grocery/masters/accounts",
    icon: ClipboardList,
    create: "Add account",
  },
  sequences: {
    eyebrow: "Numbering setup",
    title: "Document numbering",
    description:
      "Control each branch document prefix and the next number issued.",
    endpoint: "/v1/grocery/masters/sequences",
    icon: ClipboardList,
    create: "Add sequence",
  },
  cheques: {
    eyebrow: "Accounting",
    title: "Post-dated cheques",
    description:
      "Track pending, cleared, returned, and cancelled supplier cheques.",
    endpoint: "/v1/grocery/cheques",
    icon: ReceiptText,
  },
  "customer-credit": {
    eyebrow: "Customer accounts",
    title: "Customer credit",
    description: "Review credit balances, receive repayments, and place overpayments into store credit.",
    endpoint: "/v1/grocery/customer-accounts",
    icon: ReceiptText,
    create: "Record customer payment",
  },
  inventory: {
    eyebrow: "Stock control",
    title: "Stock levels",
    description:
      "Current quantity, value, reorder status, and expiry attention.",
    endpoint: "/v1/grocery/inventory",
    icon: Boxes,
  },
  expiry: {
    eyebrow: "Stock control",
    title: "Batch and expiry",
    description: "FEFO batches ordered by their expiry date.",
    endpoint: "/v1/grocery/reports/expiry",
    icon: CalendarClock,
  },
  reorder: {
    eyebrow: "Stock control",
    title: "Reorder alerts",
    description: "Products at or below their configured reorder level.",
    endpoint: "/v1/grocery/inventory",
    icon: RefreshCcw,
  },
  sales: {
    eyebrow: "Sales",
    title: "Sales history",
    description: "Completed, held, returned, and voided baskets.",
    endpoint: "/v1/grocery/sales",
    icon: ReceiptText,
  },
  "purchase-orders": {
    eyebrow: "Purchasing",
    title: "Purchase orders",
    description: "Draft and approved supplier orders with receipt progress.",
    endpoint: "/v1/grocery/purchase-orders",
    icon: ClipboardList,
    create: "New order",
  },
  "goods-receipts": {
    eyebrow: "Purchasing",
    title: "Goods receipts",
    description: "Receive supplier stock with cost, batch, and expiry.",
    endpoint: "/v1/grocery/goods-receipts",
    icon: Truck,
    create: "Receive goods",
  },
  transfers: {
    eyebrow: "Stock control",
    title: "Stock transfers",
    description: "Dispatch and receive stock between branch locations.",
    endpoint: "/v1/grocery/transfers",
    icon: RefreshCcw,
    create: "New transfer",
  },
  adjustments: {
    eyebrow: "Stock control",
    title: "Stock adjustments",
    description:
      "Authorized opening, damage, expiry, spoilage, and correction entries.",
    endpoint: "/v1/grocery/inventory",
    icon: RefreshCcw,
    create: "Adjust stock",
  },
  "stock-counts": {
    eyebrow: "Stock control",
    title: "Stock counts",
    description: "Full and cycle count snapshots with variance posting.",
    endpoint: "/v1/grocery/stock-counts",
    icon: ClipboardList,
    create: "Start count",
  },
  shifts: {
    eyebrow: "Cash control",
    title: "Cashier shifts",
    description: "Opening floats, expected cash, counted cash, and variances.",
    endpoint: "/v1/grocery/shifts",
    icon: CalendarClock,
    create: "Open shift",
  },
  expenses: {
    eyebrow: "Cash control",
    title: "Expenses",
    description: "Posted branch expenses by category and payment method.",
    endpoint: "/v1/grocery/expenses",
    icon: ReceiptText,
    create: "Record expense",
  },
  "supplier-payments": {
    eyebrow: "Supplier accounts",
    title: "Supplier payments",
    description: "Post supplier payments and update the payable balance.",
    endpoint: "/v1/grocery/reports/suppliers",
    icon: Truck,
    create: "Pay supplier",
  },
  audit: {
    eyebrow: "Administration",
    title: "Audit log",
    description:
      "Sensitive stock, sale, return, shift, price, and payment actions.",
    endpoint: "/v1/grocery/audit",
    icon: ShieldCheck,
  },
  reports: {
    eyebrow: "Management",
    title: "Reports",
    description:
      "Sales, profit, stock, expiry, supplier, shift, expense, and audit views.",
    endpoint: "/v1/grocery/reports/sales",
    icon: ClipboardList,
  },
  "purchase-returns": {
    eyebrow: "Purchasing",
    title: "Purchase returns",
    description:
      "Return eligible received stock and debit the supplier balance.",
    endpoint: "/v1/grocery/goods-receipts",
    icon: RefreshCcw,
    create: "Post return",
  },
  "sales-returns": {
    eyebrow: "Sales",
    title: "Sales returns",
    description: "Refund eligible quantities from an original sale.",
    endpoint: "/v1/grocery/sales",
    icon: RefreshCcw,
    create: "Post return",
  },
  cash: {
    eyebrow: "Cash control",
    title: "Cash movements",
    description: "Cash in, cash out, and safe drops for an open shift.",
    endpoint: "/v1/grocery/shifts",
    icon: ReceiptText,
    create: "Record movement",
  },
};

function rowsFromPayload(payload: unknown): Row[] {
  if (Array.isArray(payload)) return payload as Row[];
  if (
    payload &&
    typeof payload === "object" &&
    Array.isArray((payload as { data?: unknown }).data)
  )
    return (payload as { data: Row[] }).data;
  return [];
}

export function GroceryRecordsPage({ module }: { module: Module }) {
  const { hasPermission } = useAuth();
  const config = CONFIG[module];
  const [rows, setRows] = useState<Row[]>([]);
  const [options, setOptions] = useState<GroceryOptions | null>(null);
  const [products, setProducts] = useState<GroceryProduct[]>([]);
  const [search, setSearch] = useState("");
  const [report, setReport] = useState("sales");
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<{
    type: "success" | "error";
    text: string;
    details?: string[];
  } | null>(null);
  const [form, setForm] = useState<Record<string, string>>({});
  const [lines, setLines] = useState<Array<Record<string, string>>>([]);
  const [editingId, setEditingId] = useState<number | null>(null);

  const permissionModule =
    module === "purchase-orders" || module === "goods-receipts"
      ? "purchases"
      : module === "tax-rates"
        ? "taxes"
      : module === "sequences"
        ? "settings"
        : module === "customer-credit"
          ? "customers"
        : module;
  const canCreate = hasPermission(permissionModule, "can_create");
  const canUpdate = hasPermission(permissionModule, "can_update");
  const canDelete = hasPermission(permissionModule, "can_delete");

  const endpoint =
    module === "reports" ? `/v1/grocery/reports/${report}` : config.endpoint;
  const load = useCallback(async () => {
    try {
      const [response, optionResponse, productResponse] = await Promise.all([
        api.get<unknown>(
          endpoint +
            (search
              ? `${endpoint.includes("?") ? "&" : "?"}search=${encodeURIComponent(search)}`
              : ""),
        ),
        api.get<GroceryOptions>("/v1/grocery/options"),
        api.get<GroceryProduct[]>("/v1/grocery/products"),
      ]);
      let nextRows = rowsFromPayload(response.data);
      if (module === "reorder")
        nextRows = nextRows.filter((row) => Boolean(row.low_stock));
      setRows(nextRows);
      setOptions(optionResponse.data || null);
      setProducts(productResponse.data || []);
    } catch (error) {
      setNotice({
        type: "error",
        text: getApiErrorMessage(error, "Could not load this workspace."),
      });
    }
  }, [endpoint, module, search]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  const columns = useMemo(() => {
    const preferred: Partial<Record<Module, string[]>> = {
      products: [
        "sku",
        "name",
        "category_name",
        "barcodes",
        "base_unit_code",
        "latest_cost",
        "retail_price",
        "stock",
        "active",
      ],
      units: ["code", "name", "decimal_places", "active"],
      registers: ["code", "name", "store_id", "active"],
      promotions: [
        "name",
        "type",
        "target_type",
        "value",
        "starts_at",
        "ends_at",
        "active",
      ],
      accounts: ["code", "name", "type", "active"],
      "tax-rates": ["name", "rate", "inclusive", "active"],
      sequences: ["document_type", "prefix", "next_number"],
      cheques: ["cheque_no", "bank_name", "cheque_date", "amount", "status"],
      "customer-credit": ["code", "name", "phone", "credit_limit", "credit_balance", "advance_balance", "active"],
      "purchase-orders": [
        "order_no",
        "supplier_name",
        "order_date",
        "expected_date",
        "status",
        "grand_total",
      ],
      "goods-receipts": [
        "receipt_no",
        "supplier_name",
        "supplier_invoice_no",
        "supplier_invoice_date",
        "status",
        "grand_total",
      ],
    };
    if (preferred[module])
      return preferred[module]!.filter((key) => rows.some((row) => key in row));
    const hidden = new Set([
      "id",
      "created_by",
      "updated_at",
      "before_values",
      "after_values",
      "voided_by",
      "approved_by",
    ]);
    return Array.from(new Set(rows.flatMap((row) => Object.keys(row))))
      .filter((key) => !hidden.has(key))
      .slice(0, 8);
  }, [module, rows]);

  function value(key: string, row: Row) {
    const raw = row[key];
    if (raw === null || raw === undefined) return "—";
    if (["active", "inclusive", "weighted", "batch_tracked", "expiry_tracked"].includes(key))
      return Boolean(Number(raw)) ? "Yes" : "No";
    if (
      [
        "grand_total",
        "total",
        "sales",
        "profit",
        "cost",
        "price",
        "balance",
        "amount",
        "stock_value",
        "refund_total",
        "variance",
        "limit",
      ].some((name) => key.includes(name))
    )
      return money(Number(raw), String(options?.company?.currency || "LKR"));
    if (key.includes("quantity") || key === "stock")
      return quantity(Number(raw));
    if (typeof raw === "boolean") return raw ? "Yes" : "No";
    if (key === "barcodes" && Array.isArray(raw)) return raw.join(", ") || "—";
    if (typeof raw === "object") return JSON.stringify(raw);
    return String(raw).replaceAll("_", " ");
  }

  function set(name: string, next: string) {
    setForm((current) => ({ ...current, [name]: next }));
  }
  function addLine() {
    const product = products[0];
    setLines((current) => [
      ...current,
      {
        product_id: product ? String(product.id) : "",
        unit_id: product ? String(product.units[0]?.unit_id || "") : "",
        quantity: "1",
        unit_cost: product
          ? String(product.units[0]?.purchase_cost ?? product.latest_cost ?? 0)
          : "",
        selling_price: product
          ? String(product.units[0]?.selling_price ?? product.retail_price ?? 0)
          : "",
      },
    ]);
  }

  function openCreate() {
    if (module === "customer-credit" && !Boolean(options?.company?.customer_credit_enabled)) {
      setNotice({ type: "error", text: "Enable customer credit in Company Settings before recording repayments." });
      return;
    }
    if (module === "customer-credit" && !(options?.customers || []).some((customer) => customer.name !== "Walk-in Customer")) {
      setNotice({ type: "error", text: "Add a registered customer before recording a credit repayment." });
      return;
    }
    const lineModule = [
      "purchase-orders",
      "goods-receipts",
      "transfers",
      "adjustments",
      "stock-counts",
      "sales-returns",
      "purchase-returns",
    ].includes(module);
    if (lineModule && !products.length) {
      setNotice({
        type: "error",
        text: "Add at least one active product before starting this workflow.",
      });
      return;
    }
    const today = new Intl.DateTimeFormat("en-CA", {
      timeZone: String(options?.company?.timezone || "Asia/Colombo"),
    }).format(new Date());
    const initial: Record<string, string> = {};
    if (module === "purchase-orders") initial.order_date = today;
    if (module === "goods-receipts") initial.supplier_invoice_date = today;
    if (module === "supplier-payments") initial.payment_date = today;
    if (module === "customer-credit") initial.payment_date = today;
    if (module === "expenses") initial.expense_date = today;
    setEditingId(null);
    setForm(initial);
    setLines([]);
    setNotice(null);
    setOpen(true);
    if (lineModule) window.setTimeout(addLine, 0);
  }

  function openEdit(row: Row) {
    const barcodes = Array.isArray(row.barcodes) ? row.barcodes.join(", ") : "";
    setEditingId(Number(row.id));
    setLines([]);
    setForm(
      Object.fromEntries(
        Object.entries(row).map(([key, value]) => [
          key,
          value == null ? "" : String(value),
        ]),
      ),
    );
    setForm((current) => ({ ...current, barcode: barcodes }));
    setNotice(null);
    setOpen(true);
  }

  async function deleteRecord(row: Row) {
    if (
      !window.confirm(
        `Delete ${String(row.name || row.sku || "this record")}? Records with transaction history will be protected.`,
      )
    )
      return;
    try {
      await api.delete(`${config.endpoint}/${row.id}`);
      setNotice({
        type: "success",
        text: "Record deleted or safely deactivated.",
      });
      await load();
    } catch (error) {
      const details =
        error instanceof ApiError && error.errors
          ? Object.values(error.errors).flat()
          : undefined;
      setNotice({ type: "error", text: getApiErrorMessage(error), details });
    }
  }

  function exportCsv() {
    const keys = columns;
    const quote = (entry: unknown) =>
      `"${String(entry ?? "").replaceAll('"', '""')}"`;
    const csv = [
      keys.map(quote).join(","),
      ...rows.map((row) => keys.map((key) => quote(row[key])).join(",")),
    ].join("\r\n");
    const url = URL.createObjectURL(
      new Blob([csv], { type: "text/csv;charset=utf-8" }),
    );
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = `${module}-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
  }

  async function rowAction(
    row: Row,
    action: "approve" | "receive" | "close" | "void" | "count" | "cheque",
  ) {
    const id = Number(row.id);
    let path = "";
    let body: Record<string, unknown> = {};
    if (action === "approve")
      path = `/v1/grocery/purchase-orders/${id}/approve`;
    if (action === "receive") path = `/v1/grocery/transfers/${id}/receive`;
    if (action === "close") {
      const counted = window.prompt("Enter the counted cash for this shift:");
      if (counted === null) return;
      path = `/v1/grocery/shifts/${id}/close`;
      body = { counted_cash: Number(counted) };
    }
    if (action === "void") {
      const reason = window.prompt("Reason for voiding this completed sale:");
      if (!reason) return;
      path = `/v1/grocery/sales/${id}/void`;
      body = { reason };
    }
    if (action === "count") {
      try {
        const response = await api.get<{
          lines: Array<{
            id: number;
            sku: string;
            product_name: string;
            batch_no?: string | null;
            system_quantity: number;
          }>;
        }>(`/v1/grocery/stock-counts/${id}`);
        const countLines = response.data?.lines || [];
        const entered: Array<{ line_id: number; counted_quantity: number }> =
          [];
        for (const line of countLines) {
          const counted = window.prompt(
            `${line.sku} — ${line.product_name}${line.batch_no ? ` (${line.batch_no})` : ""}\nSystem quantity: ${line.system_quantity}\nEnter physical quantity:`,
            String(line.system_quantity),
          );
          if (counted === null) return;
          entered.push({ line_id: line.id, counted_quantity: Number(counted) });
        }
        path = `/v1/grocery/stock-counts/${id}/post`;
        body = { reason: "Physical count approved", lines: entered };
      } catch (error) {
        setNotice({
          type: "error",
          text: getApiErrorMessage(error, "Could not load count lines."),
        });
        return;
      }
    }
    if (action === "cheque") {
      const status = window.prompt(
        "Enter cheque status: cleared, returned, or cancelled",
      );
      if (!status || !["cleared", "returned", "cancelled"].includes(status))
        return;
      const reason = window.prompt("Enter the reason or bank reference:");
      if (!reason) return;
      path = `/v1/grocery/cheques/${id}`;
      body = { status, reason };
    }
    setSaving(true);
    setNotice(null);
    try {
      if (action === "cheque") await api.patch(path, body);
      else await api.post(path, body);
      setNotice({ type: "success", text: "Workflow action completed." });
      await load();
    } catch (error) {
      setNotice({
        type: "error",
        text: getApiErrorMessage(error, "Could not complete the action."),
      });
    } finally {
      setSaving(false);
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (saving) return;
    const needsProducts = [
      "purchase-orders",
      "goods-receipts",
      "transfers",
      "adjustments",
      "stock-counts",
    ].includes(module);
    if (
      needsProducts &&
      lines.some(
        (line) =>
          Number(line.product_id) < 1 ||
          (["purchase-orders", "goods-receipts"].includes(module) &&
            Number(line.unit_id) < 1),
      )
    ) {
      setNotice({
        type: "error",
        text: "Select a valid product and unit on every line before saving.",
      });
      return;
    }
    setSaving(true);
    setNotice(null);
    try {
      const numeric = (name: string, fallback = 0) =>
        Number(form[name] || fallback);
      let path = config.endpoint;
      let body: Record<string, unknown> = {};
      if (module === "products") {
        body = {
          sku: form.sku,
          name: form.name,
          local_name: form.local_name || null,
          category_id: numeric("category_id") || null,
          brand_id: numeric("brand_id") || null,
          preferred_supplier_id: numeric("preferred_supplier_id") || null,
          base_unit_id: numeric("base_unit_id", options?.units[0]?.id),
          tax_rate_id: numeric("tax_rate_id") || null,
          retail_price: numeric("retail_price"),
          wholesale_price: numeric("wholesale_price") || null,
          latest_cost: numeric("latest_cost"),
          reorder_level: numeric("reorder_level"),
          shelf_location: form.shelf_location || null,
          batch_tracked: form.batch_tracked === "true",
          expiry_tracked: form.expiry_tracked === "true",
          weighted: form.weighted === "true",
          allow_decimal_qty: form.weighted === "true",
          active: form.active !== "false",
          barcodes: form.barcode
            ? form.barcode
                .split(",")
                .map((entry) => entry.trim())
                .filter(Boolean)
            : [],
        };
      } else if (module === "units")
        body = {
          code: form.code,
          name: form.name,
          decimal_places: numeric("decimal_places"),
          active: true,
        };
      else if (module === "registers")
        body = {
          code: form.code,
          name: form.name,
          store_id: numeric("store_id", options?.stores[0]?.id),
          active: true,
        };
      else if (module === "promotions")
        body = {
          name: form.name,
          type: form.type || "percentage",
          target_type: form.target_type || "product",
          target_id:
            form.target_type === "basket"
              ? null
              : numeric("target_id", products[0]?.id),
          value: numeric("value"),
          minimum_qty: numeric("minimum_qty") || null,
          priority: 100,
          stackable: false,
          starts_at: form.starts_at,
          ends_at: form.ends_at,
          active: true,
        };
      else if (module === "accounts")
        body = {
          code: form.code,
          name: form.name,
          type: form.type || "asset",
          active: form.active !== "false",
        };
      else if (module === "tax-rates")
        body = {
          name: form.name,
          rate: numeric("rate"),
          inclusive: form.inclusive === "true",
          active: form.active !== "false",
        };
      else if (module === "sequences")
        body = {
          document_type: form.document_type || "sale",
          prefix: form.prefix,
          next_number: numeric("next_number", 1),
        };
      else if (module === "customer-credit") {
        path = "/v1/grocery/customer-payments";
        body = {
          customer_id: numeric("customer_id", options?.customers[0]?.id),
          payment_date: form.payment_date,
          amount: numeric("amount"),
          method: form.method || "cash",
          reference: form.reference || null,
        };
      }
      else if (module === "shifts") {
        path = "/v1/grocery/shifts/open";
        body = {
          register_id: numeric("register_id", options?.registers[0]?.id),
          opening_float: numeric("opening_float"),
        };
      } else if (module === "expenses") {
        path = "/v1/grocery/expenses";
        body = {
          category_id: numeric(
            "category_id",
            options?.expense_categories[0]?.id,
          ),
          expense_date: form.expense_date,
          payee: form.payee,
          amount: numeric("amount"),
          payment_method: form.payment_method || "cash",
          reference: form.reference,
        };
      } else if (module === "supplier-payments") {
        path = "/v1/grocery/supplier-payments";
        body = {
          supplier_id: numeric("supplier_id", options?.suppliers[0]?.id),
          payment_date: form.payment_date,
          amount: numeric("amount"),
          method: form.method || "cash",
          reference: form.reference,
          cheque_no: form.cheque_no || null,
          bank_name: form.bank_name || null,
          cheque_date: form.cheque_date || null,
        };
      } else if (module === "cash") {
        path = "/v1/grocery/cash-movements";
        body = {
          shift_id: options?.open_shift?.id,
          type: form.type || "cash_out",
          amount: numeric("amount"),
          reason: form.reason,
          reference: form.reference,
        };
      } else if (module === "adjustments") {
        path = "/v1/grocery/stock-adjustments";
        body = {
          store_id: numeric("store_id", options?.stores[0]?.id),
          reason: form.reason || "correction",
          notes: form.notes,
          lines: lines.map((line) => ({
            product_id: Number(line.product_id),
            product_batch_id: line.product_batch_id
              ? Number(line.product_batch_id)
              : null,
            quantity_delta: Number(line.quantity),
          })),
        };
      } else if (module === "transfers") {
        path = "/v1/grocery/transfers";
        body = {
          from_store_id: numeric("from_store_id", options?.stores[0]?.id),
          to_store_id: numeric("to_store_id", options?.stores[1]?.id),
          notes: form.notes,
          lines: lines.map((line) => ({
            product_id: Number(line.product_id),
            product_batch_id: line.product_batch_id
              ? Number(line.product_batch_id)
              : null,
            quantity: Number(line.quantity),
          })),
        };
      } else if (module === "stock-counts") {
        path = "/v1/grocery/stock-counts";
        body = {
          store_id: numeric("store_id", options?.stores[0]?.id),
          type: form.type || "cycle",
          product_ids: lines.map((line) => Number(line.product_id)),
        };
      } else if (module === "purchase-orders") {
        path = "/v1/grocery/purchase-orders";
        body = {
          supplier_id: numeric("supplier_id", options?.suppliers[0]?.id),
          store_id: numeric("store_id", options?.stores[0]?.id),
          order_date: form.order_date,
          expected_date: form.expected_date || null,
          notes: form.notes,
          lines: lines.map((line) => ({
            product_id: Number(line.product_id),
            unit_id: Number(line.unit_id),
            quantity: Number(line.quantity),
            unit_cost: Number(line.unit_cost),
            free_quantity: Number(line.free_quantity || 0),
          })),
        };
      } else if (module === "goods-receipts") {
        path = "/v1/grocery/goods-receipts";
        body = {
          supplier_id: numeric("supplier_id", options?.suppliers[0]?.id),
          store_id: numeric("store_id", options?.stores[0]?.id),
          supplier_invoice_no: form.supplier_invoice_no,
          supplier_invoice_date: form.supplier_invoice_date,
          credit_purchase: true,
          lines: lines.map((line) => ({
            product_id: Number(line.product_id),
            unit_id: Number(line.unit_id),
            quantity: Number(line.quantity),
            unit_cost: Number(line.unit_cost),
            selling_price: Number(line.selling_price || 0),
            batch_no: line.batch_no || null,
            expiry_date: line.expiry_date || null,
          })),
        };
      } else if (module === "sales-returns") {
        path = "/v1/grocery/sales-returns";
        body = {
          sale_id: numeric("sale_id"),
          store_id: numeric("store_id", options?.stores[0]?.id),
          reason: form.reason,
          refund_method: form.refund_method || "cash",
          lines: lines.map((line) => ({
            sale_line_id: Number(line.sale_line_id),
            quantity: Number(line.quantity),
            condition: line.condition || "saleable",
          })),
        };
      } else if (module === "purchase-returns") {
        path = "/v1/grocery/purchase-returns";
        body = {
          goods_receipt_id: numeric("goods_receipt_id") || null,
          supplier_id: numeric("supplier_id", options?.suppliers[0]?.id),
          store_id: numeric("store_id", options?.stores[0]?.id),
          reason: form.reason,
          lines: lines.map((line) => ({
            goods_receipt_line_id: Number(line.goods_receipt_line_id),
            quantity: Number(line.quantity),
          })),
        };
      }
      if (
        editingId &&
        [
          "products",
          "units",
          "tax-rates",
          "registers",
          "promotions",
          "accounts",
          "sequences",
        ].includes(module)
      )
        await api.put(`${path}/${editingId}`, body);
      else await api.post(path, body);
      setOpen(false);
      setEditingId(null);
      setForm({});
      setLines([]);
      await load();
      setNotice({
        type: "success",
        text: `${editingId ? "Changes saved" : "Record created"} successfully.`,
      });
    } catch (error) {
      const details =
        error instanceof ApiError && error.errors
          ? Object.values(error.errors).flat()
          : undefined;
      setNotice({
        type: "error",
        text: getApiErrorMessage(error, "Could not save the record."),
        details,
      });
    } finally {
      setSaving(false);
    }
  }

  const editableModule = [
    "products",
    "units",
    "tax-rates",
    "registers",
    "promotions",
    "accounts",
    "sequences",
  ].includes(module);
  const actionModule =
    editableModule ||
    [
      "sales",
      "purchase-orders",
      "transfers",
      "shifts",
      "stock-counts",
      "cheques",
    ].includes(module);

  return (
    <div className="space-y-6">
      <OperationHeader
        eyebrow={config.eyebrow}
        title={config.title}
        description={config.description}
        icon={config.icon}
        actions={
          <div className="flex flex-wrap gap-2">
            <button
              onClick={exportCsv}
              disabled={!rows.length}
              className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-600 disabled:opacity-40"
            >
              <Download className="h-4 w-4" /> CSV
            </button>
            <button
              onClick={() => window.print()}
              className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-600"
            >
              <Printer className="h-4 w-4" /> Print / PDF
            </button>
            {config.create && canCreate && (
              <button
                onClick={openCreate}
                className="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white"
              >
                <Plus className="h-4 w-4" />
                {config.create}
              </button>
            )}
          </div>
        }
      />
      {notice && !open && (
        <OperationNotice type={notice.type} details={notice.details}>
          {notice.text}
        </OperationNotice>
      )}
      {module === "reports" && (
        <div className="flex flex-wrap gap-2">
          {[
            "sales",
            "profit",
            "inventory",
            "expiry",
            "suppliers",
            "shifts",
            "expenses",
            "audit",
          ].map((name) => (
            <button
              key={name}
              onClick={() => setReport(name)}
              className={`rounded-xl px-3 py-2 text-xs font-bold capitalize ${report === name ? "bg-emerald-600 text-white" : "border border-slate-200 bg-white text-slate-600"}`}
            >
              {name}
            </button>
          ))}
        </div>
      )}
      <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        <div className="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
          <input
            className={`${inputClass} max-w-sm`}
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={`Search ${config.title.toLowerCase()}…`}
          />
          <span className="text-xs font-semibold text-slate-400">
            {rows.length} records
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="bg-slate-50 text-[11px] font-bold uppercase tracking-wide text-slate-500">
              <tr>
                {columns.map((column) => (
                  <th key={column} className="whitespace-nowrap px-4 py-3">
                    {column.replaceAll("_", " ")}
                  </th>
                ))}
                {actionModule && <th className="px-4 py-3">Actions</th>}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((row, index) => (
                <tr key={String(row.id || index)} className="hover:bg-slate-50">
                  {columns.map((column) => (
                    <td
                      key={column}
                      className="max-w-72 truncate whitespace-nowrap px-4 py-3 text-slate-700"
                    >
                      {value(column, row)}
                    </td>
                  ))}
                  {actionModule && (
                    <td className="whitespace-nowrap px-4 py-3">
                      <div className="flex gap-2">
                        {editableModule && canUpdate && (
                          <button
                            onClick={() => openEdit(row)}
                            className="rounded-lg border border-slate-200 p-2 text-slate-600"
                            aria-label="Edit"
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </button>
                        )}
                        {editableModule && canDelete && (
                          <button
                            onClick={() => void deleteRecord(row)}
                            className="rounded-lg bg-rose-50 p-2 text-rose-700"
                            aria-label="Delete"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        )}
                        {module === "sales" && (
                          <Link
                            href={`/dashboard/sales/${row.id}/receipt`}
                            className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-bold text-slate-600"
                          >
                            Receipt
                          </Link>
                        )}
                        {module === "sales" &&
                          row.status === "completed" &&
                          canUpdate && (
                            <button
                              disabled={saving}
                              onClick={() => void rowAction(row, "void")}
                              className="rounded-lg bg-rose-50 px-2.5 py-1.5 text-xs font-bold text-rose-700"
                            >
                              Void
                            </button>
                          )}
                        {module === "purchase-orders" &&
                          row.status === "draft" &&
                          canUpdate && (
                            <button
                              disabled={saving}
                              onClick={() => void rowAction(row, "approve")}
                              className="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700"
                            >
                              Approve
                            </button>
                          )}
                        {module === "transfers" &&
                          row.status === "dispatched" &&
                          canUpdate && (
                            <button
                              disabled={saving}
                              onClick={() => void rowAction(row, "receive")}
                              className="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700"
                            >
                              Receive
                            </button>
                          )}
                        {module === "shifts" &&
                          row.status === "open" &&
                          canUpdate && (
                            <button
                              disabled={saving}
                              onClick={() => void rowAction(row, "close")}
                              className="rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-800"
                            >
                              Close shift
                            </button>
                          )}
                          {module === "stock-counts" &&
                            row.status === "counting" &&
                            canUpdate && (
                            <button
                              disabled={saving}
                              onClick={() => void rowAction(row, "count")}
                              className="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700"
                            >
                              Enter & post count
                            </button>
                          )}
                          {module === "cheques" && row.status === "pending" && canUpdate && (
                            <button
                              disabled={saving}
                              onClick={() => void rowAction(row, "cheque")}
                              className="rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-800"
                            >
                              Update status
                            </button>
                          )}
                      </div>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!rows.length && (
          <div className="py-20 text-center text-sm text-slate-400">
            No records match the current view.
          </div>
        )}
      </section>

      {open && (
        <OperationModal
          title={
            editingId ? `Edit ${config.title}` : config.create || config.title
          }
          description="Complete the required fields, then save once."
          onClose={() => !saving && setOpen(false)}
        >
          <form onSubmit={submit} className="space-y-5 p-6">
            {notice && (
              <OperationNotice type={notice.type} details={notice.details}>
                {notice.text}
              </OperationNotice>
            )}
            <ModuleForm
              module={module}
              form={form}
              set={set}
              options={options}
              products={products}
              lines={lines}
              setLines={setLines}
              addLine={addLine}
            />
            <div className="flex justify-end gap-2 border-t border-slate-200 pt-5">
              <button
                type="button"
                onClick={() => setOpen(false)}
                disabled={saving}
                className="rounded-xl px-4 py-2.5 text-sm font-bold text-slate-500"
              >
                Cancel
              </button>
              <button
                disabled={saving}
                className="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50"
              >
                {saving
                  ? "Saving…"
                  : editingId
                    ? "Save changes"
                    : "Create record"}
              </button>
            </div>
          </form>
        </OperationModal>
      )}
    </div>
  );
}

function ModuleForm({
  module,
  form,
  set,
  options,
  products,
  lines,
  setLines,
  addLine,
}: {
  module: Module;
  form: Record<string, string>;
  set: (name: string, value: string) => void;
  options: GroceryOptions | null;
  products: GroceryProduct[];
  lines: Array<Record<string, string>>;
  setLines: React.Dispatch<React.SetStateAction<Array<Record<string, string>>>>;
  addLine: () => void;
}) {
  const currency = String(options?.company?.currency || "LKR");
  const field = (
    name: string,
    label: string,
    type = "text",
    required = true,
  ) => (
    <OperationField label={label} required={required}>
      <input
        className={inputClass}
        type={type}
        value={form[name] || ""}
        onChange={(event) => set(name, event.target.value)}
        required={required}
      />
    </OperationField>
  );
  const select = (
    name: string,
    label: string,
    values: Array<{ value: string | number; label: string }>,
  ) => (
    <OperationField label={label}>
      <select
        className={inputClass}
        value={form[name] || values[0]?.value || ""}
        onChange={(event) => set(name, event.target.value)}
      >
        {values.map((item) => (
          <option key={item.value} value={item.value}>
            {item.label}
          </option>
        ))}
      </select>
    </OperationField>
  );
  const storeSelect = (name = "store_id", label = "Store") =>
    select(
      name,
      label,
      (options?.stores || []).map((item) => ({
        value: item.id,
        label: item.name,
      })),
    );
  const supplierSelect = () =>
    select(
      "supplier_id",
      "Supplier",
      (options?.suppliers || []).map((item) => ({
        value: item.id,
        label: item.name,
      })),
    );
  const hasLines = [
    "purchase-orders",
    "goods-receipts",
    "transfers",
    "adjustments",
    "stock-counts",
    "sales-returns",
    "purchase-returns",
  ].includes(module);
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {module === "products" && (
          <>
            {field("sku", "SKU")}
            {field("name", "Product name")}
            {field("local_name", "Second-language name", "text", false)}
            {field("barcode", "Barcode(s), separated by commas", "text", false)}
            {select("category_id", "Category", [
              { value: "", label: "No category" },
              ...(options?.categories || []).map((item) => ({
                value: item.id,
                label: item.name,
              })),
            ])}
            {select("brand_id", "Brand", [
              { value: "", label: "No brand" },
              ...(options?.brands || []).map((item) => ({
                value: item.id,
                label: item.name,
              })),
            ])}
            {select("preferred_supplier_id", "Preferred supplier", [
              { value: "", label: "No preferred supplier" },
              ...(options?.suppliers || []).map((item) => ({
                value: item.id,
                label: item.name,
              })),
            ])}
            {select(
              "base_unit_id",
              "Base unit",
              (options?.units || []).map((unit) => ({
                value: unit.id,
                label: `${unit.code} — ${unit.name}`,
              })),
            )}
            {select("tax_rate_id", "Tax rate", [
              { value: "", label: "No tax" },
              ...(options?.tax_rates || []).map((item) => ({
                value: item.id,
                label: `${item.name} (${item.rate}%)`,
              })),
            ])}
            {field("latest_cost", "Purchase price", "number")}
            {field("retail_price", "Selling price", "number")}
            {field("wholesale_price", "Wholesale price", "number", false)}
            {field("reorder_level", "Reorder level", "number")}
            {field("shelf_location", "Shelf location", "text", false)}
            {select("batch_tracked", "Batch tracking", [
              { value: "false", label: "No" },
              { value: "true", label: "Yes" },
            ])}
            {select("expiry_tracked", "Expiry tracking", [
              { value: "false", label: "No" },
              { value: "true", label: "Yes" },
            ])}
            {select("weighted", "Weighted product", [
              { value: "false", label: "No" },
              { value: "true", label: "Yes" },
            ])}
            {select("active", "Status", [
              { value: "true", label: "Active" },
              { value: "false", label: "Inactive" },
            ])}
          </>
        )}
        {module === "units" && (
          <>
            {field("code", "Code")}
            {field("name", "Name")}
            {field("decimal_places", "Decimal places", "number")}
          </>
        )}
        {module === "tax-rates" && (
          <>
            {field("name", "Tax name")}
            {field("rate", "Rate (%)", "number")}
            {select("inclusive", "Price includes tax", [
              { value: "false", label: "No — add tax at checkout" },
              { value: "true", label: "Yes — tax is included" },
            ])}
            {select("active", "Status", [
              { value: "true", label: "Active" },
              { value: "false", label: "Inactive" },
            ])}
          </>
        )}
        {module === "registers" && (
          <>
            {field("code", "Register code")}
            {field("name", "Register name")}
            {storeSelect()}
          </>
        )}
        {module === "promotions" && (
          <>
            {field("name", "Promotion name")}
            {select(
              "type",
              "Type",
              [
                "percentage",
                "fixed",
                "price",
                "buy_x_get_y",
                "quantity_break",
              ].map((v) => ({ value: v, label: v.replaceAll("_", " ") })),
            )}
            {select(
              "target_type",
              "Target",
              ["product", "category", "brand", "basket"].map((v) => ({
                value: v,
                label: v,
              })),
            )}
            {select(
              "target_id",
              "Target product",
              products.map((p) => ({ value: p.id, label: p.name })),
            )}
            {field("value", "Discount / price value", "number")}
            {field("minimum_qty", "Minimum quantity", "number")}
            {field("starts_at", "Starts at", "datetime-local")}
            {field("ends_at", "Ends at", "datetime-local")}
          </>
        )}
        {module === "accounts" && (
          <>
            {field("code", "Account code")}
            {field("name", "Account name")}
            {select(
              "type",
              "Account type",
              ["asset", "liability", "equity", "income", "expense"].map(
                (value) => ({ value, label: value }),
              ),
            )}
            {select("active", "Status", [
              { value: "true", label: "Active" },
              { value: "false", label: "Inactive" },
            ])}
          </>
        )}
        {module === "sequences" && (
          <>
            {select(
              "document_type",
              "Document type",
              [
                "sale",
                "purchase_order",
                "goods_receipt",
                  "return",
                  "shift",
                "purchase_return",
                  "transfer",
                  "adjustment",
                "stock_count",
                  "supplier_payment",
                  "customer_payment",
                  "expense",
              ].map((value) => ({ value, label: value.replaceAll("_", " ") })),
            )}
            {field("prefix", "Prefix")}
            {field("next_number", "Next number", "number")}
          </>
        )}
        {module === "shifts" && (
          <>
            {select(
              "register_id",
              "Register",
              (options?.registers || []).map((r) => ({
                value: r.id,
                label: r.name,
              })),
            )}
            {field("opening_float", "Opening float", "number")}
          </>
        )}
        {module === "expenses" && (
          <>
            {select(
              "category_id",
              "Category",
              (options?.expense_categories || []).map((c) => ({
                value: c.id,
                label: c.name,
              })),
            )}
            {field("expense_date", "Date", "date")}
          {field("payee", "Payee", "text", false)}
            {field("amount", "Amount", "number")}
            {select(
              "payment_method",
              "Payment method",
              ["cash", "card", "bank_transfer", "mobile"].map((v) => ({
                value: v,
                label: v.replaceAll("_", " "),
              })),
            )}
          {field("reference", "Reference", "text", false)}
          </>
        )}
      {module === "supplier-payments" && (
          <>
            {supplierSelect()}
            {field("payment_date", "Payment date", "date")}
            {field("amount", "Amount", "number")}
            {select(
              "method",
              "Method",
              [
                "cash",
                "card",
                "bank_transfer",
                ...(Boolean(options?.company?.post_dated_cheques_enabled)
                  ? ["cheque"]
                  : []),
              ].map((v) => ({ value: v, label: v.replaceAll("_", " ") })),
            )}
          {field("reference", "Reference", "text", false)}
            {form.method === "cheque" && (
              <>
                {field("cheque_no", "Cheque number")}
                {field("bank_name", "Bank name")}
                {field("cheque_date", "Cheque date", "date")}
              </>
            )}
          </>
        )}
        {module === "cash" && (
          <>
            {select(
              "type",
              "Movement type",
              ["cash_in", "cash_out", "cash_drop"].map((v) => ({
                value: v,
                label: v.replaceAll("_", " "),
              })),
            )}
            {field("amount", "Amount", "number")}
            {field("reason", "Reason")}
            {field("reference", "Reference")}
          </>
        )}
        {module === "adjustments" && (
          <>
            {storeSelect()}
            {select(
              "reason",
              "Reason",
              [
                "damage",
                "spoilage",
                "expiry",
                "theft",
                "correction",
                "opening",
              ].map((v) => ({ value: v, label: v })),
            )}
            {field("notes", "Notes")}
          </>
        )}
        {module === "transfers" && (
          <>
            {storeSelect("from_store_id", "Source store")}
            {storeSelect("to_store_id", "Destination store")}
            {field("notes", "Notes")}
          </>
        )}
        {module === "stock-counts" && (
          <>
            {storeSelect()}
            {select("type", "Count type", [
              { value: "cycle", label: "Cycle count" },
              { value: "full", label: "Full count" },
            ])}
          </>
        )}
        {module === "purchase-orders" && (
          <>
            {supplierSelect()}
            {storeSelect()}
            {field("order_date", "Order date", "date")}
          {field("expected_date", "Expected date", "date", false)}
          {field("notes", "Notes", "text", false)}
          </>
        )}
        {module === "goods-receipts" && (
          <>
            {supplierSelect()}
            {storeSelect()}
            {field("supplier_invoice_no", "Supplier invoice")}
            {field("supplier_invoice_date", "Invoice date", "date")}
          </>
        )}
        {module === "sales-returns" && (
          <>
            {field("sale_id", "Sale ID", "number")}
            {storeSelect()}
            {field("reason", "Return reason")}
            {select(
              "refund_method",
              "Refund method",
              [
                "cash",
                "card",
                "bank_transfer",
                "mobile",
                "store_credit",
                "exchange",
              ].map((v) => ({ value: v, label: v.replaceAll("_", " ") })),
            )}
          </>
        )}
        {module === "purchase-returns" && (
          <>
            {field("goods_receipt_id", "Goods receipt ID", "number")}
            {supplierSelect()}
            {storeSelect()}
            {field("reason", "Return reason")}
          </>
        )}
      </div>
      {hasLines && (
        <div className="rounded-2xl border border-slate-200">
          <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <div>
              <p className="text-sm font-bold">Product lines</p>
              <p className="text-xs text-slate-500">
                Scan a barcode or search across up to 1,000 active products.
              </p>
            </div>
            <button
              type="button"
              onClick={addLine}
              className="text-xs font-bold text-emerald-700"
            >
              + Add line
            </button>
          </div>
          <div className="space-y-3 p-4">
            {lines.map((line, index) => {
              const product = products.find(
                (item) => item.id === Number(line.product_id),
              );
              const update = (
                name: string,
                value: string,
                extra: Record<string, string> = {},
              ) =>
                setLines((current) =>
                  current.map((row, i) =>
                    i === index ? { ...row, [name]: value, ...extra } : row,
                  ),
                );
              const selectProduct = (selected: GroceryProduct) => {
                const unit = selected.units[0];
                update("product_id", String(selected.id), {
                  unit_id: String(unit?.unit_id || ""),
                  unit_cost: String(
                    unit?.purchase_cost ?? selected.latest_cost ?? 0,
                  ),
                  selling_price: String(
                    unit?.selling_price ?? selected.retail_price ?? 0,
                  ),
                });
              };
              const lineTotal =
                Number(line.quantity || 0) * Number(line.unit_cost || 0);
              return (
                <div
                  key={index}
                  className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"
                >
                  <div className="grid gap-3 lg:grid-cols-12">
                    <OperationField
                      label="Product"
                      required
                      className="lg:col-span-5"
                    >
                      <SearchableProductPicker
                        products={products}
                        value={Number(line.product_id) || undefined}
                        onSelect={selectProduct}
                        currency={currency}
                      />
                    </OperationField>
                    {["purchase-orders", "goods-receipts"].includes(module) && (
                      <OperationField
                        label="Unit"
                        required
                        className="lg:col-span-2"
                      >
                        <select
                          className={inputClass}
                          value={line.unit_id || ""}
                          onChange={(event) => {
                            const unit = product?.units.find(
                              (item) =>
                                item.unit_id === Number(event.target.value),
                            );
                            update("unit_id", event.target.value, {
                              unit_cost: String(
                                unit?.purchase_cost ??
                                  product?.latest_cost ??
                                  0,
                              ),
                              selling_price: String(
                                unit?.selling_price ??
                                  product?.retail_price ??
                                  0,
                              ),
                            });
                          }}
                        >
                          <option value="">Select unit</option>
                          {(product?.units || []).map((unit) => (
                            <option key={unit.unit_id} value={unit.unit_id}>
                              {unit.code} — x{unit.conversion_factor}
                            </option>
                          ))}
                        </select>
                      </OperationField>
                    )}
                    <OperationField
                      label={
                        module === "adjustments"
                          ? "Quantity change"
                          : "Quantity"
                      }
                      required
                      className="lg:col-span-2"
                    >
                      <input
                        className={inputClass}
                        type="number"
                        step="0.001"
                        value={line.quantity || "1"}
                        onChange={(event) =>
                          update("quantity", event.target.value)
                        }
                      />
                    </OperationField>
                    {["purchase-orders", "goods-receipts"].includes(module) && (
                      <OperationField
                        label="Purchase price"
                        required
                        className="lg:col-span-2"
                      >
                        <input
                          className={inputClass}
                          type="number"
                          step="0.0001"
                          min="0"
                          value={line.unit_cost || ""}
                          onChange={(event) =>
                            update("unit_cost", event.target.value)
                          }
                        />
                      </OperationField>
                    )}
                    <button
                      type="button"
                      onClick={() =>
                        setLines((current) =>
                          current.filter((_, i) => i !== index),
                        )
                      }
                      className="self-end rounded-xl px-3 py-2.5 text-xs font-bold text-rose-600 lg:col-span-1"
                    >
                      Remove
                    </button>
                  </div>
                  {module === "goods-receipts" && (
                    <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                      <OperationField label="Selling price" required>
                        <input
                          className={inputClass}
                          type="number"
                          step="0.0001"
                          min="0"
                          value={line.selling_price || ""}
                          onChange={(event) =>
                            update("selling_price", event.target.value)
                          }
                        />
                      </OperationField>
                      <OperationField label="Batch number">
                        <input
                          className={inputClass}
                          value={line.batch_no || ""}
                          onChange={(event) =>
                            update("batch_no", event.target.value)
                          }
                        />
                      </OperationField>
                      <OperationField label="Expiry date">
                        <input
                          className={inputClass}
                          type="date"
                          value={line.expiry_date || ""}
                          onChange={(event) =>
                            update("expiry_date", event.target.value)
                          }
                        />
                      </OperationField>
                      <div className="rounded-xl bg-emerald-50 p-3 text-right">
                        <p className="text-[11px] font-bold uppercase text-emerald-700">
                          Line purchase total
                        </p>
                        <p className="mt-1 font-black text-emerald-950">
                        {money(lineTotal, currency)}
                        </p>
                      </div>
                    </div>
                  )}
                  {module === "purchase-orders" && (
                    <div className="mt-3 text-right text-sm font-bold text-slate-700">
                    Line total: {money(lineTotal, currency)}
                    </div>
                  )}
                  {module === "sales-returns" && (
                    <OperationField
                      label="Original sale line ID"
                      className="mt-3"
                    >
                      <input
                        className={inputClass}
                        type="number"
                        value={line.sale_line_id || ""}
                        onChange={(event) =>
                          update("sale_line_id", event.target.value)
                        }
                      />
                    </OperationField>
                  )}
                  {module === "purchase-returns" && (
                    <OperationField
                      label="Original receipt line ID"
                      className="mt-3"
                    >
                      <input
                        className={inputClass}
                        type="number"
                        value={line.goods_receipt_line_id || ""}
                        onChange={(event) =>
                          update("goods_receipt_line_id", event.target.value)
                        }
                      />
                    </OperationField>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}
      {module === "customer-credit" && (
        <>
          {select(
            "customer_id",
            "Customer",
            (options?.customers || []).filter((customer) => customer.name !== "Walk-in Customer").map((customer) => ({ value: customer.id, label: customer.name }))
          )}
          {field("payment_date", "Payment date", "date")}
          {field("amount", "Amount received", "number")}
          {select(
            "method",
            "Payment method",
            ["cash", "card", "bank_transfer", "mobile"].map((value) => ({ value, label: value.replaceAll("_", " ") }))
          )}
          {field("reference", "Reference", "text", false)}
        </>
      )}
    </>
  );
}
