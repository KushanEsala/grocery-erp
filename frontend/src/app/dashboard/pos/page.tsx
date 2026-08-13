'use client';

import {
  FormEvent,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  ArrowLeft,
  ArrowRight,
  Barcode,
  Banknote,
  Check,
  CreditCard,
  Edit3,
  Maximize2,
  Minimize2,
  Pause,
  Play,
  Plus,
  Printer,
  ReceiptText,
  Search,
  ShoppingCart,
  Trash2,
  WalletCards,
  X,
} from 'lucide-react';
import { api, getApiErrorMessage } from '@/lib/api';
import {
  GroceryOptions,
  GroceryProduct,
  GroceryUnit,
  PosLine,
  money as formatMoney,
  quantity,
} from '@/lib/grocery';
import {
  OperationField,
  OperationHeader,
  OperationNotice,
} from '@/components/operation-ui';
import { SearchableEntityPicker } from '@/components/searchable-entity-picker';

const inputClass =
  'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100';
const compactInputClass =
  'w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-emerald-500 focus:ring-4 focus:ring-emerald-100';

type Notice = { type: 'success' | 'error' | 'warning'; text: string };
type PrintableSale = {
  invoice_no: string;
  sold_at: string;
  customer_name?: string | null;
  register_name?: string | null;
  cashier_name?: string | null;
  subtotal: number;
  discount_total: number;
  tax_total: number;
  grand_total: number;
  lines: Array<{
    id: number;
    description: string;
    quantity: number;
    unit_code: string;
    unit_price: number;
    discount_total: number;
    line_total: number;
  }>;
  payments: Array<{ id: number; method: string; amount: number }>;
  company?: {
    name?: string;
    address?: string;
    phone?: string;
    tax_number?: string;
    currency?: string;
    receipt_footer?: string;
  } | null;
};

export default function PointOfSalePage() {
  const [options, setOptions] = useState<GroceryOptions | null>(null);
  const [products, setProducts] = useState<GroceryProduct[]>([]);
  const [cart, setCart] = useState<PosLine[]>([]);
  const [draftLine, setDraftLine] = useState<PosLine | null>(null);
  const [editingKey, setEditingKey] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | null>(
    null
  );
  const [storeId, setStoreId] = useState(0);
  const [customerId, setCustomerId] = useState<number | null>(null);
  const [paymentMethod, setPaymentMethod] = useState('cash');
  const [splitPayment, setSplitPayment] = useState(false);
  const [secondaryMethod, setSecondaryMethod] = useState('card');
  const [secondaryAmount, setSecondaryAmount] = useState('');
  const [tendered, setTendered] = useState('');
  const [billDiscountType, setBillDiscountType] = useState<'amount' | 'percent'>('amount');
  const [billDiscountValue, setBillDiscountValue] = useState('');
  const [holdReference, setHoldReference] = useState('');
  const [openingFloat, setOpeningFloat] = useState('0');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [lastSale, setLastSale] = useState<{
    id: number;
    invoice_no: string;
  } | null>(null);
  const [heldSales, setHeldSales] = useState<
    Array<{ id: number; invoice_no: string; grand_total: number; hold_reference?: string | null }>
  >([]);
  const [heldSaleId, setHeldSaleId] = useState<number | null>(null);
  const [resumeOpen, setResumeOpen] = useState(false);
  const [focusMode, setFocusMode] = useState(false);
  const [activeItemField, setActiveItemField] = useState<
    'scan' | 'quantity' | 'price' | 'discount'
  >('scan');
  const searchRef = useRef<HTMLInputElement>(null);
  const quantityRef = useRef<HTMLInputElement>(null);
  const unitPriceRef = useRef<HTMLInputElement>(null);
  const discountRef = useRef<HTMLInputElement>(null);
  const initialLoadStartedRef = useRef(false);

  const currency = String(options?.company?.currency || 'LKR');
  const money = (value: number | string | null | undefined) =>
    formatMoney(value, currency);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const optionResponse = await api.get<GroceryOptions>(
        '/v1/grocery/options'
      );
      const nextOptions = optionResponse.data!;
      setOptions(nextOptions);
      const nextStore =
        storeId ||
        (nextOptions.open_shift
          ? nextOptions.registers.find(
              (register) =>
                register.id === nextOptions.open_shift?.register_id
            )?.store_id
          : nextOptions.stores[0]?.id);
      const resolvedStore = Number(nextStore || nextOptions.stores[0]?.id || 0);
      setStoreId(resolvedStore);
      const productResponse = await api.get<GroceryProduct[]>(
        `/v1/grocery/lookups/products?store_id=${resolvedStore}&limit=100`
      );
      setProducts(productResponse.data || []);
      const heldResponse = await api.get<{
        data: Array<{ id: number; invoice_no: string; grand_total: number; hold_reference?: string | null }>;
      }>('/v1/grocery/sales?status=held');
      setHeldSales(heldResponse.data?.data || []);
      if (!customerId) {
        setCustomerId(
          nextOptions.customers.find((customer) => customer.Code === 'WALK-IN')
            ?.id || null
        );
      }
    } catch (error) {
      setNotice({
        type: 'error',
        text: getApiErrorMessage(error, 'Could not load the checkout.'),
      });
    } finally {
      setLoading(false);
      window.setTimeout(() => searchRef.current?.focus(), 50);
    }
  }, [customerId, storeId]);

  useEffect(() => {
    if (initialLoadStartedRef.current) return;
    initialLoadStartedRef.current = true;
    void load();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const handler = (event: KeyboardEvent) => {
      if (event.key === 'Enter' && event.shiftKey && !draftLine && cart.length) {
        event.preventDefault();
        editCartLine(cart[cart.length - 1]);
        return;
      }
      if (event.key === 'Escape' && draftLine) {
        event.preventDefault();
        clearDraft();
        return;
      }
      if (event.key === 'F2') {
        event.preventDefault();
        searchRef.current?.focus();
        setActiveItemField('scan');
      }
      if (event.key === 'F6' && cart.length && !saving) {
        event.preventDefault();
        void pauseSale();
      }
      if (event.key === 'F7' && heldSales.length) {
        event.preventDefault();
        setResumeOpen((current) => !current);
      }
      if (event.key === 'F8') {
        event.preventDefault();
        void completeSale();
      }
      if (event.key === 'F9' && lastSale && !saving) {
        event.preventDefault();
        void printLastReceipt();
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  });

  useEffect(() => {
    const sync = () => {
      if (!document.fullscreenElement) setFocusMode(false);
    };
    document.addEventListener('fullscreenchange', sync);
    return () => document.removeEventListener('fullscreenchange', sync);
  }, []);

  useEffect(() => {
    const term = search.trim();
    if (term.length < 2) return;
    const timer = window.setTimeout(async () => {
      try {
        const response = await api.get<GroceryProduct[]>(
          `/v1/grocery/lookups/products?store_id=${storeId}&limit=100&search=${encodeURIComponent(term)}`
        );
        const matches = response.data || [];
        setProducts((current) => {
          const merged = new Map(current.map((product) => [product.id, product]));
          matches.forEach((product) => merged.set(product.id, product));
          return Array.from(merged.values());
        });
      } catch {
        // The normal checkout notice handles API availability; keep typing responsive.
      }
    }, 180);
    return () => window.clearTimeout(timer);
  }, [search, storeId]);

  const promotions = useMemo(
    () => options?.promotions || [],
    [options?.promotions]
  );

  const priceLine = useCallback(
    (line: PosLine) => {
      const gross = line.quantity * line.unitPrice;
      const eligible = promotions.filter((promotion) => {
        const now = Date.now();
        const starts = new Date(promotion.starts_at).getTime();
        const ends = new Date(promotion.ends_at).getTime();
        const target =
          promotion.target_type === 'basket' ||
          (promotion.target_type === 'product' &&
            promotion.target_id === line.product.id) ||
          (promotion.target_type === 'category' &&
            promotion.target_id === line.product.category_id) ||
          (promotion.target_type === 'brand' &&
            promotion.target_id === line.product.brand_id);
        return (
          target &&
          now >= starts &&
          now <= ends &&
          (!promotion.minimum_qty ||
            line.quantity >= Number(promotion.minimum_qty)) &&
          (!promotion.minimum_subtotal ||
            gross >= Number(promotion.minimum_subtotal))
        );
      });
      const promotionDiscount = eligible.reduce((best, promotion) => {
        const value = Number(promotion.value);
        let next = 0;
        if (
          promotion.type === 'percentage' ||
          promotion.type === 'quantity_break'
        ) {
          next = (gross * value) / 100;
        }
        if (promotion.type === 'fixed') next = value;
        if (promotion.type === 'price') {
          next = Math.max(0, gross - line.quantity * value);
        }
        if (promotion.type === 'buy_x_get_y' && promotion.buy_qty && promotion.get_qty) {
          next =
            Math.floor(
              line.quantity /
                (Number(promotion.buy_qty) + Number(promotion.get_qty))
            ) *
            Number(promotion.get_qty) *
            (gross / line.quantity);
        }
        return Math.max(best, next);
      }, 0);
      const manualDiscount =
        (line.discountType || 'amount') === 'percent'
          ? gross * Math.min(100, Math.max(0, Number(line.discountValue ?? 0))) / 100
          : Math.max(0, Number(line.discountValue ?? line.discount ?? 0));
      const lineDiscount = Math.min(
        gross,
        Math.max(manualDiscount, promotionDiscount)
      );
      const afterDiscount = gross - lineDiscount;
      const rate = Number(line.product.tax_rate || 0);
      const lineTax = Boolean(line.product.tax_inclusive)
        ? afterDiscount - afterDiscount / (1 + rate / 100)
        : (afterDiscount * rate) / 100;
      const lineTotal = Boolean(line.product.tax_inclusive)
        ? afterDiscount
        : afterDiscount + lineTax;
      return { key: line.key, gross, discount: lineDiscount, tax: lineTax, total: lineTotal };
    },
    [promotions]
  );

  const pricing = useMemo(
    () => cart.map((line) => priceLine(line)),
    [cart, priceLine]
  );
  const draftPrice = useMemo(
    () => (draftLine ? priceLine(draftLine) : null),
    [draftLine, priceLine]
  );
  const subtotal = pricing.reduce((sum, line) => sum + line.gross, 0);
  const discount = pricing.reduce((sum, line) => sum + line.discount, 0);
  const tax = pricing.reduce((sum, line) => sum + line.tax, 0);
  const beforeBillDiscount = Math.max(
    0,
    pricing.reduce((sum, line) => sum + line.total, 0)
  );
  const billDiscount = Math.min(
    beforeBillDiscount,
    billDiscountType === 'percent'
      ? beforeBillDiscount * Math.min(100, Math.max(0, Number(billDiscountValue || 0))) / 100
      : Math.max(0, Number(billDiscountValue || 0))
  );
  const total = Math.max(0, beforeBillDiscount - billDiscount);
  const secondaryDue = splitPayment
    ? Math.min(total, Math.max(0, Number(secondaryAmount || 0)))
    : 0;
  const primaryDue = Math.max(0, total - secondaryDue);
  const change = Math.max(0, Number(tendered || 0) - primaryDue);

  const paymentMethods = useMemo<Array<[string, typeof Banknote]>>(
    () => [
      ['cash', Banknote],
      ['card', CreditCard],
      ['bank_transfer', CreditCard],
      ['mobile', WalletCards],
      ...(Boolean(options?.company?.customer_credit_enabled)
        ? [
            ['credit', WalletCards] as [string, typeof Banknote],
            ['store_credit', WalletCards] as [string, typeof Banknote],
          ]
        : []),
    ],
    [options?.company?.customer_credit_enabled]
  );

  const categoryTabs = useMemo(() => {
    const productCategoryIds = new Set(
      products
        .map((product) => product.category_id)
        .filter((id): id is number => typeof id === 'number')
    );
    return (options?.categories || []).filter((category) =>
      productCategoryIds.has(category.id)
    );
  }, [options?.categories, products]);

  const visibleProducts = useMemo(() => {
    const value = search.trim().toLowerCase();
    if (value) {
      return products
        .filter(
          (product) =>
            product.sku.toLowerCase().includes(value) ||
            product.name.toLowerCase().includes(value) ||
            product.barcodes.some((barcode) => barcode === search.trim())
        )
        .slice(0, 20);
    }

    return products
      .filter((product) =>
        selectedCategoryId ? product.category_id === selectedCategoryId : true
      )
      .sort(
        (left, right) =>
          Number(right.sold_quantity || 0) - Number(left.sold_quantity || 0) ||
          Number(right.stock || 0) - Number(left.stock || 0) ||
          left.name.localeCompare(right.name)
      )
      .slice(0, 10);
  }, [products, search, selectedCategoryId]);

  function lineKey(product: GroceryProduct, unit: GroceryUnit) {
    return `${product.id}:${unit.unit_id}`;
  }

  function createLine(
    product: GroceryProduct,
    scannedQuantity = 1
  ): PosLine | null {
    const unit =
      product.units.find((candidate) => candidate.unit_id === product.base_unit_id) ||
      product.units[0];
    if (!unit) return null;
    return {
      key: lineKey(product, unit),
      product,
      unit,
      quantity: scannedQuantity,
      unitPrice: Number(unit.selling_price ?? product.retail_price),
      discount: 0,
      discountType: 'amount',
      discountValue: 0,
    };
  }

  function startProcessingProduct(
    product: GroceryProduct,
    scannedQuantity = 1
  ) {
    // The completed receipt belongs to the previous transaction. Remove its
    // action as soon as the cashier begins a new bill.
    setLastSale(null);
    const next = createLine(product, scannedQuantity);
    if (!next) return;

    if (draftLine && draftLine.key !== next.key) {
      setNotice({
        type: 'warning',
        text: 'Add or clear the current processing item before selecting another product.',
      });
      return;
    }

    const existing = cart.find((line) => line.key === next.key);
    if (draftLine) {
      setDraftLine({
        ...draftLine,
        quantity: draftLine.quantity + scannedQuantity,
      });
    } else if (existing) {
      setDraftLine({
        ...existing,
        quantity: existing.quantity + scannedQuantity,
      });
      setEditingKey(existing.key);
    } else {
      setDraftLine(next);
      setEditingKey(null);
    }

    setSearch('');
    setNotice(null);
    window.setTimeout(() => {
      quantityRef.current?.focus();
      quantityRef.current?.select();
      setActiveItemField('quantity');
    }, 0);
  }

  async function submitSearch(event: FormEvent) {
    event.preventDefault();
    const prefix = String(options?.company?.scale_barcode_prefix || '');
    const productDigits = Number(options?.company?.scale_product_digits || 5);
    const weightDigits = Number(options?.company?.scale_weight_digits || 5);
    if (
      prefix &&
      search.startsWith(prefix) &&
      search.length >= prefix.length + productDigits + weightDigits
    ) {
      const productCode = search.slice(prefix.length, prefix.length + productDigits);
      const weight =
        Number(
          search.slice(
            prefix.length + productDigits,
            prefix.length + productDigits + weightDigits
          )
        ) / 1000;
      const scaledProduct = products.find(
        (product) =>
          product.sku === productCode ||
          product.barcodes.some((barcode) => barcode === `${prefix}${productCode}`)
      );
      if (scaledProduct && weight > 0) {
        startProcessingProduct(scaledProduct, weight);
        return;
      }
    }

    let exact = products.find(
      (product) =>
        product.barcodes.includes(search.trim()) ||
        product.sku.toLowerCase() === search.trim().toLowerCase()
    );
    if (!exact && search.trim()) {
      try {
        const response = await api.get<GroceryProduct[]>(
          `/v1/grocery/lookups/products?store_id=${storeId}&limit=20&search=${encodeURIComponent(search.trim())}`
        );
        const matches = response.data || [];
        exact = matches.find(
          (product) =>
            product.barcodes.includes(search.trim()) ||
            product.sku.toLowerCase() === search.trim().toLowerCase()
        ) || (matches.length === 1 ? matches[0] : undefined);
        if (exact) {
          setProducts((current) => current.some((product) => product.id === exact?.id) ? current : [...current, exact!]);
        }
      } catch {
        setNotice({ type: 'error', text: 'Could not search the product catalogue.' });
        return;
      }
    }
    if (exact) {
      startProcessingProduct(exact);
    } else if (visibleProducts.length === 1) {
      startProcessingProduct(visibleProducts[0]);
    } else if (search.trim()) {
      setNotice({
        type: 'warning',
        text: 'Select the matching product from the results.',
      });
    }
  }

  function updateDraft(
    update: Partial<Pick<PosLine, 'quantity' | 'unit' | 'unitPrice' | 'discount' | 'discountType' | 'discountValue'>>
  ) {
    setDraftLine((current) => {
      if (!current) return current;
      const unit = update.unit || current.unit;
      return {
        ...current,
        ...update,
        key: lineKey(current.product, unit),
        unit,
        unitPrice: update.unit
          ? Number(update.unit.selling_price ?? current.product.retail_price)
          : update.unitPrice ?? current.unitPrice,
      };
    });
  }

  function focusDraftField(field: 'quantity' | 'price' | 'discount') {
    const target =
      field === 'quantity'
        ? quantityRef.current
        : field === 'price'
          ? unitPriceRef.current
          : discountRef.current;
    target?.focus();
    target?.select();
    setActiveItemField(field);
  }

  function moveDraftBackward() {
    if (activeItemField === 'discount') {
      focusDraftField('price');
    } else if (activeItemField === 'price') {
      focusDraftField('quantity');
    } else {
      searchRef.current?.focus();
      setActiveItemField('scan');
    }
  }

  function moveDraftForward() {
    if (activeItemField === 'quantity') {
      focusDraftField('price');
    } else if (activeItemField === 'price') {
      focusDraftField('discount');
    } else {
      confirmDraft();
    }
  }

  function confirmDraft() {
    if (!draftLine) return;
    if (draftLine.quantity <= 0) {
      setNotice({ type: 'warning', text: 'Quantity must be greater than zero.' });
      return;
    }

    setCart((current) => {
      const withoutEdited = editingKey
        ? current.filter((line) => line.key !== editingKey)
        : current;
      const existing = withoutEdited.find((line) => line.key === draftLine.key);
      if (existing) {
        return withoutEdited.map((line) =>
          line.key === draftLine.key
            ? {
                ...draftLine,
                quantity: line.quantity + draftLine.quantity,
                discount: line.discount + draftLine.discount,
                discountValue:
                  (line.discountType || 'amount') === (draftLine.discountType || 'amount')
                    ? Number(line.discountValue ?? line.discount) + Number(draftLine.discountValue ?? draftLine.discount)
                    : Number(draftLine.discountValue ?? draftLine.discount),
              }
            : line
        );
      }
      return [...withoutEdited, draftLine];
    });
    setDraftLine(null);
    setEditingKey(null);
    setNotice(null);
    setActiveItemField('scan');
    searchRef.current?.focus();
  }

  function clearDraft() {
    setDraftLine(null);
    setEditingKey(null);
    setActiveItemField('scan');
    searchRef.current?.focus();
  }

  function editCartLine(line: PosLine) {
    if (draftLine && editingKey !== line.key) {
      setNotice({
        type: 'warning',
        text: 'Finish or clear the current processing item before editing another line.',
      });
      return;
    }
    setDraftLine({ ...line });
    setEditingKey(line.key);
    setNotice(null);
    window.setTimeout(() => {
      quantityRef.current?.focus();
      quantityRef.current?.select();
      setActiveItemField('quantity');
    }, 0);
  }

  function removeCartLine(key: string) {
    setCart((current) => current.filter((line) => line.key !== key));
    if (editingKey === key) clearDraft();
  }

  async function resumeHeldSale(id: number) {
    if (
      (cart.length || draftLine) &&
      !window.confirm('Resume this paused sale and replace the current bill?')
    ) {
      return;
    }
    setSaving(true);
    setNotice(null);
    try {
      const response = await api.get<{
        id: number;
        store_id: number;
        hold_reference?: string | null;
        lines: Array<{
          product_id: number;
          unit_id: number;
          quantity: number;
          unit_price: number;
          discount_total: number;
        }>;
      }>(`/v1/grocery/sales/${id}`);
      const held = response.data;
      if (!held) return;
      const resumed = held.lines.flatMap((line) => {
        const product = products.find(
          (candidate) => candidate.id === line.product_id
        );
        const unit = product?.units.find(
          (candidate) => candidate.unit_id === line.unit_id
        );
        return product && unit
          ? [
              {
                key: lineKey(product, unit),
                product,
                unit,
                quantity: Number(line.quantity),
                unitPrice: Number(line.unit_price),
                discount: Number(line.discount_total),
                discountType: 'amount' as const,
                discountValue: Number(line.discount_total),
              },
            ]
          : [];
      });
      setCart(resumed);
      setLastSale(null);
      setDraftLine(null);
      setEditingKey(null);
      setStoreId(Number(held.store_id));
      setHeldSaleId(id);
      setHoldReference(String(held.hold_reference || ''));
      setHeldSales((current) => current.filter((sale) => sale.id !== id));
      setResumeOpen(false);
      setNotice({
        type: 'success',
        text: 'Paused sale resumed. Complete payment or pause it again.',
      });
      searchRef.current?.focus();
    } catch (error) {
      setNotice({
        type: 'error',
        text: getApiErrorMessage(error, 'Could not resume the held sale.'),
      });
    } finally {
      setSaving(false);
    }
  }

  async function openShift() {
    if (!options?.registers[0]) return;
    setSaving(true);
    try {
      await api.post('/v1/grocery/shifts/open', {
        register_id: options.registers[0].id,
        opening_float: Number(openingFloat),
      });
      setNotice({ type: 'success', text: 'Register shift opened. Checkout is ready.' });
      await load();
    } catch (error) {
      setNotice({ type: 'error', text: getApiErrorMessage(error) });
    } finally {
      setSaving(false);
    }
  }

  async function saveSale(hold: boolean, referenceOverride?: string) {
    if (draftLine) {
      setNotice({
        type: 'warning',
        text: 'Add or clear the processing item before saving the sale.',
      });
      return;
    }
    if (!cart.length || !storeId) return;
    setSaving(true);
    setNotice(null);
    try {
      const body = {
        store_id: storeId,
        register_id: options?.open_shift?.register_id,
        shift_id: options?.open_shift?.id,
        held_sale_id: heldSaleId,
        customer_id: customerId,
        lines: cart.map((line) => ({
          product_id: line.product.id,
          unit_id: line.unit.unit_id,
          quantity: line.quantity,
          unit_price: line.unitPrice,
          discount: priceLine(line).discount,
        })),
        bill_discount_type: billDiscountType,
        bill_discount_value: Number(billDiscountValue || 0),
        hold_reference: hold ? (referenceOverride || holdReference) : null,
        payments: hold
          ? []
          : [
              ...(primaryDue > 0
                ? [
                    {
                      method: paymentMethod,
                      amount: primaryDue,
                      tendered:
                        paymentMethod === 'cash'
                          ? Number(tendered || primaryDue)
                          : primaryDue,
                    },
                  ]
                : []),
              ...(secondaryDue > 0
                ? [
                    {
                      method: secondaryMethod,
                      amount: secondaryDue,
                      tendered: secondaryDue,
                    },
                  ]
                : []),
            ],
      };
      const response = await api.post<{ id: number; invoice_no: string }>(
        hold ? '/v1/grocery/pos/hold' : '/v1/grocery/pos/complete',
        body
      );
      setNotice({
        type: 'success',
        text: hold
          ? `Sale ${response.data?.invoice_no} paused.`
          : `Sale ${response.data?.invoice_no} completed. Receipt is ready.`,
      });
      setLastSale(
        !hold && response.data
          ? { id: response.data.id, invoice_no: response.data.invoice_no }
          : null
      );
      setCart([]);
      setTendered('');
      setSecondaryAmount('');
      setSplitPayment(false);
      setBillDiscountValue('');
      setBillDiscountType('amount');
      setHoldReference('');
      setHeldSaleId(null);
      await load();
    } catch (error) {
      setNotice({
        type: 'error',
        text: getApiErrorMessage(error, 'Could not complete the sale.'),
      });
    } finally {
      setSaving(false);
    }
  }

  async function pauseSale() {
    const reference = holdReference || String(Math.floor(1000 + Math.random() * 9000));
    setHoldReference(reference);
    await saveSale(true, reference);
  }

  async function completeSale() {
    await saveSale(false);
  }

  async function printLastReceipt() {
    if (!lastSale || printing) return;
    setPrinting(true);
    setNotice(null);
    try {
      const response = await api.post<PrintableSale>(
        `/v1/grocery/sales/${lastSale.id}/print`,
        {}
      );
      const sale = response.data;
      if (!sale) throw new Error('The completed receipt could not be loaded.');

      const escapeHtml = (value: unknown) =>
        String(value ?? '')
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#039;');
      const receiptMoney = (value: number) =>
        formatMoney(value, sale.company?.currency || currency);
      const lineMarkup = sale.lines
        .map(
          (line) => `
            <div class="line">
              <strong>${escapeHtml(line.description)}</strong>
              <div class="spread"><span>${escapeHtml(quantity(line.quantity))} ${escapeHtml(line.unit_code)} × ${escapeHtml(receiptMoney(line.unit_price))}</span><span>${escapeHtml(receiptMoney(line.line_total))}</span></div>
              ${Number(line.discount_total) > 0 ? `<div class="spread small"><span>Discount</span><span>-${escapeHtml(receiptMoney(line.discount_total))}</span></div>` : ''}
            </div>`
        )
        .join('');
      const paymentMarkup = sale.payments
        .map(
          (payment) =>
            `<div class="spread"><span>${escapeHtml(payment.method.replaceAll('_', ' '))}</span><span>${escapeHtml(receiptMoney(payment.amount))}</span></div>`
        )
        .join('');

      const frame = document.createElement('iframe');
      frame.title = `Print receipt ${sale.invoice_no}`;
      frame.setAttribute('aria-hidden', 'true');
      frame.style.position = 'fixed';
      frame.style.right = '0';
      frame.style.bottom = '0';
      frame.style.width = '1px';
      frame.style.height = '1px';
      frame.style.border = '0';
      frame.style.opacity = '0';
      document.body.appendChild(frame);
      const printDocument = frame.contentDocument;
      if (!printDocument) throw new Error('The browser could not prepare the receipt.');
      printDocument.open();
      printDocument.write(`<!doctype html><html><head><title>${escapeHtml(sale.invoice_no)}</title><style>
        @page { size: 80mm auto; margin: 5mm; }
        * { box-sizing: border-box; }
        body { width: 70mm; margin: 0 auto; color: #000; font: 11px/1.4 ui-monospace, Consolas, monospace; }
        h1 { margin: 0; font-size: 17px; } header, footer { text-align: center; }
        .meta, .totals { margin: 12px 0; padding: 9px 0; border-top: 1px dashed #000; border-bottom: 1px dashed #000; }
        .spread { display: flex; justify-content: space-between; gap: 12px; } .spread span:last-child { text-align: right; }
        .line { margin: 0 0 9px; } .small { font-size: 10px; } .total { margin-top: 5px; font-size: 14px; font-weight: 800; }
        footer { margin-top: 16px; white-space: pre-line; }
      </style></head><body>
        <header><h1>${escapeHtml(sale.company?.name || 'Grocery ERP')}</h1><div>${escapeHtml(sale.company?.address || '')}</div><div>${escapeHtml(sale.company?.phone || '')}</div></header>
        <section class="meta"><div class="spread"><span>Invoice</span><strong>${escapeHtml(sale.invoice_no)}</strong></div><div class="spread"><span>Date</span><span>${escapeHtml(new Date(sale.sold_at).toLocaleString())}</span></div><div class="spread"><span>Register</span><span>${escapeHtml(sale.register_name || '-')}</span></div><div class="spread"><span>Cashier</span><span>${escapeHtml(sale.cashier_name || '-')}</span></div><div class="spread"><span>Customer</span><span>${escapeHtml(sale.customer_name || 'Walk-in Customer')}</span></div></section>
        <section>${lineMarkup}</section>
        <section class="totals"><div class="spread"><span>Subtotal</span><span>${escapeHtml(receiptMoney(sale.subtotal))}</span></div><div class="spread"><span>Discount</span><span>-${escapeHtml(receiptMoney(sale.discount_total))}</span></div><div class="spread"><span>Tax</span><span>${escapeHtml(receiptMoney(sale.tax_total))}</span></div><div class="spread total"><span>TOTAL</span><span>${escapeHtml(receiptMoney(sale.grand_total))}</span></div></section>
        <section>${paymentMarkup}</section><footer>${escapeHtml(sale.company?.receipt_footer || 'Thank you for shopping with us.')}</footer>
      </body></html>`);
      printDocument.close();

      window.setTimeout(() => {
        const printWindow = frame.contentWindow;
        if (!printWindow) {
          frame.remove();
          setPrinting(false);
          return;
        }
        printWindow.focus();
        printWindow.print();
        window.setTimeout(() => frame.remove(), 1000);
        setPrinting(false);
      }, 100);
    } catch (error) {
      setNotice({
        type: 'error',
        text: getApiErrorMessage(error, 'Could not prepare the receipt for printing.'),
      });
      setPrinting(false);
    }
  }

  async function toggleFocusMode() {
    if (!focusMode) {
      try {
        await document.documentElement.requestFullscreen?.();
      } catch {}
      setFocusMode(true);
    } else {
      if (document.fullscreenElement) await document.exitFullscreen();
      setFocusMode(false);
    }
  }

  const saleBlockedByDraft = Boolean(draftLine);
  const lineCountLabel = `${cart.length} confirmed line${cart.length === 1 ? '' : 's'}`;

  if (loading && !options) {
    return (
      <div className="flex min-h-[60vh] items-center justify-center text-sm font-semibold text-slate-500">
        Preparing checkout...
      </div>
    );
  }

  return (
    <div
      className={
        focusMode
          ? 'fixed inset-0 z-[100] overflow-hidden bg-slate-100 p-3'
          : 'space-y-5'
      }
    >
      {!focusMode && (
        <OperationHeader
          eyebrow="Front counter"
          title="Point of sale"
          description="Process one item at a time. F6 pauses, F7 opens paused sales, and F8 takes payment."
          icon={ShoppingCart}
          actions={
            <button
              onClick={() => void toggleFocusMode()}
              className="inline-flex items-center gap-2 rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white"
            >
              <Maximize2 className="h-4 w-4" />
              Full-screen checkout
            </button>
          }
        />
      )}

      {focusMode && (
        <button
          onClick={() => void toggleFocusMode()}
          className="fixed right-5 top-5 z-[140] inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-slate-800 shadow-xl ring-1 ring-slate-200"
        >
          <Minimize2 className="h-4 w-4" />
          Exit full screen
        </button>
      )}

      {notice &&
        (focusMode ? (
          <div className="fixed left-1/2 top-4 z-[135] w-[min(680px,calc(100vw-2rem))] -translate-x-1/2">
            <OperationNotice type={notice.type}>{notice.text}</OperationNotice>
          </div>
        ) : (
          <OperationNotice type={notice.type}>{notice.text}</OperationNotice>
        ))}

      {lastSale && focusMode && (
        <div className="fixed left-1/2 top-20 z-[130] flex w-[min(520px,calc(100vw-2rem))] -translate-x-1/2 items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950 shadow-xl">
          <span>
            <strong>{lastSale.invoice_no}</strong> is ready to print.
          </span>
          <button
            type="button"
            onClick={() => void printLastReceipt()}
            disabled={printing}
            className="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-3 py-2 text-xs font-bold text-white"
          >
            <Printer className="h-4 w-4" />
            {printing ? 'Preparing...' : 'Print (F9)'}
          </button>
        </div>
      )}

      {lastSale && !focusMode && (
        <div className="flex flex-col gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-950 shadow-sm sm:flex-row sm:items-center sm:justify-between">
          <span>
            Receipt <strong>{lastSale.invoice_no}</strong> is ready to print.
          </span>
          <button
            type="button"
            onClick={() => void printLastReceipt()}
            disabled={printing}
            className="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-[#237a55] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#174a38]"
          >
            <Printer className="h-4 w-4" />
            {printing ? 'Preparing...' : 'Print receipt'}
            {!printing && <kbd className="rounded bg-white/15 px-1.5 py-0.5 font-mono text-[10px]">F9</kbd>}
          </button>
        </div>
      )}

      {!options?.open_shift && (
        <section className="rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <p className="font-bold text-amber-950">Open the register first</p>
              <p className="mt-1 text-sm text-amber-800">
                Enter the opening cash float before accepting payments.
              </p>
            </div>
            <div className="flex items-end gap-2">
              <OperationField label="Opening float">
                <input
                  className={inputClass}
                  value={openingFloat}
                  onChange={(event) => setOpeningFloat(event.target.value)}
                  type="number"
                  min="0"
                />
              </OperationField>
              <button
                onClick={openShift}
                disabled={saving}
                className="rounded-xl bg-amber-950 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50"
              >
                Open shift
              </button>
            </div>
          </div>
        </section>
      )}

      <div
        className={`grid min-h-0 gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(390px,.72fr)] ${
          focusMode ? 'h-full' : 'min-h-[720px]'
        }`}
      >
        <section className="flex min-h-0 flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="grid shrink-0 gap-3 border-b border-slate-200 bg-slate-950 p-4 text-white lg:grid-cols-[1fr_260px]">
            <form onSubmit={submitSearch} className="relative">
              <Barcode className="absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-emerald-300" />
              <input
                ref={searchRef}
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                onFocus={() => setActiveItemField('scan')}
                placeholder="Scan barcode or search product (F2)"
                className="w-full rounded-2xl border border-white/15 bg-white/10 py-3.5 pl-12 pr-24 text-base text-white outline-none placeholder:text-slate-400 focus:border-emerald-400 focus:ring-4 focus:ring-emerald-400/15"
              />
              <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded-lg border border-white/15 bg-white/10 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-emerald-100">
                Enter
              </span>
            </form>
            <div className="rounded-2xl bg-white text-slate-900">
              <SearchableEntityPicker
                key={customerId || 'walk-in'}
                resource="customers"
                items={options?.customers || []}
                value={customerId}
                onSelect={(customer) => setCustomerId(customer.id)}
                label="Customer"
              />
            </div>
          </div>

          <div className="shrink-0 border-b border-slate-100 bg-white px-4 py-3">
            <div className="content-scrollbar flex gap-2 overflow-x-auto pb-1">
              <button
                type="button"
                onClick={() => setSelectedCategoryId(null)}
                className={`whitespace-nowrap rounded-full px-4 py-2 text-xs font-black transition ${
                  selectedCategoryId === null
                    ? 'bg-emerald-600 text-white'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                Frequent
              </button>
              {categoryTabs.map((category) => (
                <button
                  key={category.id}
                  type="button"
                  onClick={() => setSelectedCategoryId(category.id)}
                  className={`whitespace-nowrap rounded-full px-4 py-2 text-xs font-black transition ${
                    selectedCategoryId === category.id
                      ? 'bg-emerald-600 text-white'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  {category.name}
                </button>
              ))}
            </div>
          </div>

          <div className="shrink-0 p-4">
            <div className="mb-3 flex items-center justify-between gap-3">
              <div>
                <p className="text-sm font-black text-slate-950">
                  {search.trim()
                    ? 'Search results'
                    : selectedCategoryId
                      ? 'Top products in category'
                      : 'Most used products'}
                </p>
                <p className="text-xs text-slate-500">
                  Showing {visibleProducts.length} of {products.length} products
                </p>
              </div>
              {draftLine && (
                <span className="rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-200">
                  Finish processing item
                </span>
              )}
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-5">
              {visibleProducts.map((product) => (
                <button
                  key={product.id}
                  onClick={() => startProcessingProduct(product)}
                  className="group min-h-24 rounded-2xl border border-slate-200 p-3 text-left transition hover:-translate-y-0.5 hover:border-emerald-300 hover:shadow-lg hover:shadow-emerald-100"
                >
                  <div className="flex items-start justify-between gap-3">
                    <span className="rounded-lg bg-slate-100 px-2 py-1 font-mono text-[11px] font-bold text-slate-500">
                      {product.sku}
                    </span>
                    <Plus className="h-4 w-4 text-emerald-600 opacity-0 transition group-hover:opacity-100" />
                  </div>
                  <p className="mt-2 line-clamp-2 text-sm font-bold text-slate-950">
                    {product.name}
                  </p>
                  <div className="mt-2 flex items-end justify-between gap-2">
                    <span className="truncate text-xs text-slate-500">
                      {quantity(product.stock)} {product.base_unit_code}
                    </span>
                    <span className="whitespace-nowrap text-sm font-bold text-emerald-700">
                      {money(product.retail_price)}
                    </span>
                  </div>
                </button>
              ))}
              {!visibleProducts.length && (
                <div className="col-span-full flex min-h-36 flex-col items-center justify-center text-center text-slate-400">
                  <Search className="h-8 w-8" />
                  <p className="mt-3 text-sm font-semibold">
                    No product matches this scan.
                  </p>
                </div>
              )}
            </div>
          </div>

          <div className="flex min-h-0 flex-1 flex-col border-t border-slate-200 bg-slate-50/70">
            <div className="flex shrink-0 items-center justify-between gap-3 px-4 py-3">
              <div>
                <p className="text-sm font-black text-slate-950">Confirmed items</p>
                <p className="text-xs text-slate-500">
                  {lineCountLabel} on this bill
                </p>
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={!cart.length || Boolean(draftLine)}
                  onClick={() => cart.length && editCartLine(cart[cart.length - 1])}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-600 transition hover:border-emerald-300 hover:text-emerald-700 disabled:cursor-not-allowed disabled:opacity-40"
                  title="Edit the most recently added item (Shift+Enter)"
                >
                  <Edit3 className="h-3.5 w-3.5" />
                  Edit last
                  <kbd className="rounded bg-slate-100 px-1 py-0.5 font-mono text-[9px]">Shift+Enter</kbd>
                </button>
                <span className="rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-500 ring-1 ring-slate-200">
                  {money(total)}
                </span>
              </div>
            </div>
            <div className="content-scrollbar min-h-0 flex-1 overflow-y-auto px-4 pb-4">
              {cart.length ? (
                <div className="space-y-2">
                  {cart.map((line) => {
                    const lineTotal =
                      pricing.find((item) => item.key === line.key)?.total || 0;
                    return (
                      <div
                        key={line.key}
                        className={`grid gap-3 rounded-2xl border bg-white p-3 text-sm shadow-sm lg:grid-cols-[minmax(0,1.2fr)_120px_120px_120px_88px] lg:items-center ${
                          editingKey === line.key
                            ? 'border-amber-300 ring-2 ring-amber-100'
                            : 'border-slate-200'
                        }`}
                      >
                        <div className="min-w-0">
                          <p className="truncate font-black text-slate-950">
                            {line.product.name}
                          </p>
                          <p className="mt-0.5 text-xs text-slate-500">
                            {line.product.sku} - {line.product.category_name || 'No category'}
                          </p>
                        </div>
                        <div>
                          <p className="text-[11px] font-bold uppercase text-slate-400">
                            Quantity
                          </p>
                          <p className="font-bold text-slate-800">
                            {quantity(line.quantity)} {line.unit.code}
                          </p>
                        </div>
                        <div>
                          <p className="text-[11px] font-bold uppercase text-slate-400">
                            Unit price
                          </p>
                          <p className="font-bold text-slate-800">
                            {money(line.unitPrice)}
                          </p>
                        </div>
                        <div>
                          <p className="text-[11px] font-bold uppercase text-slate-400">
                            Line total
                          </p>
                          <p className="font-black text-slate-950">
                            {money(lineTotal)}
                          </p>
                        </div>
                        <div className="flex justify-end gap-1">
                          <button
                            type="button"
                            onClick={() => editCartLine(line)}
                            className="rounded-lg p-2 text-slate-500 transition hover:bg-emerald-50 hover:text-emerald-700"
                            aria-label="Edit line"
                            title="Edit this item"
                          >
                            <Edit3 className="h-4 w-4" />
                          </button>
                          <button
                            type="button"
                            onClick={() => removeCartLine(line.key)}
                            className="rounded-lg p-2 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                            aria-label="Remove line"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </div>
                      </div>
                    );
                  })}
                </div>
              ) : (
                <div className="flex h-full min-h-48 flex-col items-center justify-center text-center text-slate-400">
                  <ShoppingCart className="h-9 w-9" />
                  <p className="mt-3 text-sm font-semibold">
                    Confirmed items will appear here.
                  </p>
                </div>
              )}
            </div>
          </div>
        </section>

        <aside className="flex min-h-0 flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
          <div className="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4">
            <div>
              <p className="font-bold text-slate-950">Processing item</p>
              <p className="text-xs text-slate-500">
                {draftLine
                  ? editingKey
                    ? 'Editing a confirmed line'
                    : 'Review before adding to bill'
                  : 'Scan or choose one product'}
              </p>
            </div>
            <ReceiptText className="h-5 w-5 text-slate-400" />
          </div>

          <div className="shrink-0 p-4">
            {draftLine ? (
              <div className="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate text-base font-black text-slate-950">
                      {draftLine.product.name}
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                      {draftLine.product.sku} - Stock {quantity(draftLine.product.stock)} {draftLine.product.base_unit_code}
                    </p>
                  </div>
                  <button
                    type="button"
                    onClick={clearDraft}
                    className="rounded-lg p-1.5 text-slate-500 transition hover:bg-white hover:text-slate-900"
                    aria-label="Clear processing item"
                  >
                    <X className="h-4 w-4" />
                  </button>
                </div>

                <div className="mt-4 space-y-3">
                  <OperationField label="1 · Quantity">
                    <div className={`flex items-stretch gap-2 rounded-xl transition ${activeItemField === 'quantity' ? 'bg-emerald-100/70 p-1.5 ring-2 ring-emerald-400' : ''}`}>
                      <div className="relative min-w-0 flex-1">
                        <input
                          ref={quantityRef}
                          aria-label="Item quantity"
                          className={`${compactInputClass} pr-16`}
                          type="number"
                          min="0.001"
                          step={draftLine.product.allow_decimal_qty ? '0.001' : '1'}
                          value={draftLine.quantity}
                          onChange={(event) =>
                            updateDraft({ quantity: Number(event.target.value) })
                          }
                          onFocus={() => setActiveItemField('quantity')}
                          onKeyDown={(event) => {
                            if (event.key === 'Enter' && event.shiftKey) {
                              event.preventDefault();
                              moveDraftBackward();
                            } else if (event.key === 'Enter') {
                              event.preventDefault();
                              moveDraftForward();
                            }
                          }}
                        />
                        <span className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded-md bg-slate-100 px-2 py-1 text-[10px] font-black text-slate-600">
                          {draftLine.unit.code}
                        </span>
                      </div>
                      <span className="flex w-16 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-100 text-[10px] font-bold uppercase text-slate-500">
                        Enter ↓
                      </span>
                    </div>
                  </OperationField>
                  <OperationField label="2 · Unit price">
                    <div className={`rounded-xl transition ${activeItemField === 'price' ? 'bg-emerald-100/70 p-1.5 ring-2 ring-emerald-400' : ''}`}>
                    <input
                      ref={unitPriceRef}
                      aria-label="Item unit price"
                      className={compactInputClass}
                      type="number"
                      min="0"
                      step="0.01"
                      value={draftLine.unitPrice}
                      onChange={(event) =>
                        updateDraft({ unitPrice: Number(event.target.value) })
                      }
                      onFocus={() => setActiveItemField('price')}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter' && event.shiftKey) {
                          event.preventDefault();
                          moveDraftBackward();
                        } else if (event.key === 'Enter') {
                          event.preventDefault();
                          moveDraftForward();
                        }
                      }}
                    />
                    </div>
                  </OperationField>
                  <OperationField label="3 · Item discount">
                    <div className={`rounded-xl transition ${activeItemField === 'discount' ? 'bg-emerald-100/70 p-1.5 ring-2 ring-emerald-400' : ''}`}>
                    <input
                      ref={discountRef}
                      aria-label="Item discount value"
                      className={compactInputClass}
                      type="number"
                      min="0"
                      max={(draftLine.discountType || 'amount') === 'percent' ? 100 : undefined}
                      step="0.01"
                      value={draftLine.discountValue ?? draftLine.discount}
                      onChange={(event) =>
                        updateDraft({
                          discountValue: Number(event.target.value),
                          discount: Number(event.target.value),
                        })
                      }
                      onFocus={() => setActiveItemField('discount')}
                      onKeyDown={(event) => {
                        if (event.key === 'Enter' && event.shiftKey) {
                          event.preventDefault();
                          moveDraftBackward();
                        } else if (event.key === 'Enter') {
                          event.preventDefault();
                          moveDraftForward();
                        }
                      }}
                    />
                    <label className="mt-1.5 flex cursor-pointer items-center gap-2 text-[11px] font-semibold text-slate-600">
                      <input
                        type="checkbox"
                        checked={draftLine.discountType === 'percent'}
                        onChange={(event) =>
                          updateDraft({
                            discountType: event.target.checked ? 'percent' : 'amount',
                          })
                        }
                      />
                      Percentage (%)
                    </label>
                    </div>
                  </OperationField>
                </div>

                <div className="mt-4 rounded-xl bg-white p-3 ring-1 ring-emerald-100">
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-slate-500">Line total</span>
                    <span className="text-lg font-black text-slate-950">
                      {money(draftPrice?.total || 0)}
                    </span>
                  </div>
                  {Boolean(draftPrice?.discount) && (
                    <div className="mt-1 flex items-center justify-between text-xs text-slate-500">
                      <span>Discount</span>
                      <span>-{money(draftPrice?.discount || 0)}</span>
                    </div>
                  )}
                </div>

                <div className="mt-4 grid grid-cols-[auto_auto_1fr] gap-2">
                  <button
                    type="button"
                    onClick={moveDraftBackward}
                    className="inline-flex items-center justify-center gap-1 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-600 transition hover:bg-slate-50"
                    title="Previous field (Shift+Enter)"
                  >
                    <ArrowLeft className="h-4 w-4" />
                    Back <kbd className="rounded bg-slate-100 px-1 py-0.5 font-mono text-[9px]">Shift+Enter</kbd>
                  </button>
                  <button
                    type="button"
                    onClick={clearDraft}
                    className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-600 transition hover:bg-slate-50"
                  >
                    Clear <kbd className="ml-1 rounded bg-slate-100 px-1 py-0.5 font-mono text-[9px]">Esc</kbd>
                  </button>
                  <button
                    type="button"
                    onClick={moveDraftForward}
                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-black text-white transition hover:bg-emerald-700"
                  >
                    {activeItemField === 'discount' ? <Check className="h-4 w-4" /> : null}
                    {activeItemField === 'discount'
                      ? editingKey
                        ? 'Update item'
                        : 'Add item'
                      : 'Next field'}
                    {activeItemField !== 'discount' && <ArrowRight className="h-4 w-4" />}
                    <kbd className="rounded bg-white/15 px-1.5 py-0.5 font-mono text-[9px]">Enter</kbd>
                  </button>
                </div>
              </div>
            ) : (
              <div className="flex min-h-64 flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 text-center text-slate-400">
                <Barcode className="h-9 w-9" />
                <p className="mt-3 text-sm font-bold text-slate-500">
                  No item processing
                </p>
                <p className="mt-1 max-w-52 text-xs">
                  Scan a barcode or choose a product from the frequent list.
                </p>
              </div>
            )}
          </div>

          <div className={`min-h-0 flex-1 border-t border-slate-200 bg-slate-50 ${focusMode ? 'p-3' : 'p-4'}`}>
            <div className={focusMode ? 'grid grid-cols-3 gap-2 text-xs' : 'space-y-1.5 text-sm'}>
              <div className={`flex justify-between text-slate-500 ${focusMode ? 'rounded-lg bg-white px-2.5 py-2 ring-1 ring-slate-200' : ''}`}>
                <span>Subtotal</span>
                <span className="font-bold text-slate-700">{money(subtotal)}</span>
              </div>
              <div className={`flex justify-between text-slate-500 ${focusMode ? 'rounded-lg bg-white px-2.5 py-2 ring-1 ring-slate-200' : ''}`}>
                <span>Discount</span>
                <span className="font-bold text-slate-700">-{money(discount)}</span>
              </div>
              {tax > 0 && (
                <div className={`flex justify-between text-slate-500 ${focusMode ? 'rounded-lg bg-white px-2.5 py-2 ring-1 ring-slate-200' : ''}`}>
                  <span>Tax</span>
                  <span className="font-bold text-slate-700">{money(tax)}</span>
                </div>
              )}
              <div className={`flex justify-between font-black text-slate-950 ${focusMode ? 'col-span-3 rounded-xl bg-slate-950 px-3 py-2.5 text-base text-white' : 'border-t border-slate-200 pt-3 text-xl'}`}>
                <span>Total</span>
                <span>{money(total)}</span>
              </div>
            </div>

            <div className="mt-2">
              <OperationField label="Whole-bill discount">
                <input
                  aria-label="Whole-bill discount value"
                  className={compactInputClass}
                  type="number"
                  min="0"
                  max={billDiscountType === 'percent' ? 100 : beforeBillDiscount}
                  step="0.01"
                  value={billDiscountValue}
                  onChange={(event) => setBillDiscountValue(event.target.value)}
                />
                <label className="mt-1.5 flex cursor-pointer items-center gap-2 text-[11px] font-semibold text-slate-600">
                  <input
                    type="checkbox"
                    checked={billDiscountType === 'percent'}
                    onChange={(event) =>
                      setBillDiscountType(event.target.checked ? 'percent' : 'amount')
                    }
                  />
                  Percentage (%)
                </label>
              </OperationField>
            </div>
            {billDiscount > 0 && (
              <div className="mt-1 flex justify-between text-[11px] font-bold text-emerald-700">
                <span>Bill discount applied</span>
                <span>-{money(billDiscount)}</span>
              </div>
            )}

            <div className="mt-2 grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2">
              <OperationField label="Payment method">
                <select
                  aria-label="Payment method"
                  className={compactInputClass}
                  value={paymentMethod}
                  onChange={(event) => setPaymentMethod(event.target.value)}
                >
                  {paymentMethods.map(([method]) => (
                    <option key={method} value={method}>
                      {method.replaceAll('_', ' ')}
                    </option>
                  ))}
                </select>
              </OperationField>
              <label className="flex h-9 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600">
                <input
                  type="checkbox"
                  checked={splitPayment}
                  onChange={(event) => setSplitPayment(event.target.checked)}
                  className="h-4 w-4 accent-emerald-600"
                />
                Split
              </label>
            </div>

            {splitPayment && (
              <div className="mt-2 grid grid-cols-2 gap-2">
                <OperationField label="Second method">
                  <select
                    className={compactInputClass}
                    value={secondaryMethod}
                    onChange={(event) => setSecondaryMethod(event.target.value)}
                  >
                    <option value="card">Card</option>
                    <option value="bank_transfer">Bank transfer</option>
                    <option value="mobile">Mobile / QR</option>
                    <option value="cash">Cash</option>
                  </select>
                </OperationField>
                <OperationField label="Second amount">
                  <input
                    className={compactInputClass}
                    type="number"
                    min="0.01"
                    max={total}
                    value={secondaryAmount}
                    onChange={(event) => setSecondaryAmount(event.target.value)}
                  />
                </OperationField>
              </div>
            )}

            {paymentMethod === 'cash' && (
              <div className={`${focusMode ? 'mt-2' : 'mt-3'} grid grid-cols-2 gap-2`}>
                <OperationField label="Cash received">
                  <input
                    className={compactInputClass}
                    type="number"
                    min={primaryDue}
                    value={tendered}
                    onChange={(event) => setTendered(event.target.value)}
                    placeholder={primaryDue.toFixed(2)}
                  />
                </OperationField>
                <div className="rounded-xl bg-white p-3 ring-1 ring-slate-200">
                  <p className="text-[11px] font-semibold uppercase text-slate-400">
                    Change
                  </p>
                  <p className="mt-1 font-black text-emerald-700">
                    {money(change)}
                  </p>
                </div>
              </div>
            )}

            {splitPayment && (
              <div className="mt-2 flex justify-between rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-900">
                <span>Primary payment</span>
                <span>{money(primaryDue)}</span>
              </div>
            )}

            <div className={`${focusMode ? 'mt-2' : 'mt-3'} flex items-end justify-between gap-2`}>
              <div className="flex items-end gap-1.5">
                <label className="block w-20">
                  <span className="mb-1 block text-[10px] font-bold uppercase text-slate-400">Pause ID</span>
                  <input
                    aria-label="Pause reference"
                    className="w-full rounded-lg border border-slate-200 bg-white px-2 py-2 text-center text-xs font-black tracking-widest text-slate-800 outline-none focus:border-amber-400"
                    value={holdReference}
                    maxLength={20}
                    onChange={(event) => setHoldReference(event.target.value.replace(/[^A-Za-z0-9-]/g, ''))}
                    placeholder="Auto"
                  />
                </label>
                <button
                  type="button"
                  title={
                    cart.length
                      ? 'Pause this sale (F6)'
                      : 'Add an item before pausing'
                  }
                  disabled={!cart.length || saving || saleBlockedByDraft}
                  onClick={() => void pauseSale()}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-white px-2.5 py-2 text-xs font-bold text-amber-800 transition hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-40"
                >
                  <Pause className="h-3.5 w-3.5" />
                  Pause
                </button>
                <button
                  type="button"
                  title={heldSales.length ? 'Resume a paused sale (F7)' : 'No paused sales'}
                  disabled={!heldSales.length || saving}
                  aria-expanded={resumeOpen}
                  onClick={() => setResumeOpen((current) => !current)}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                >
                  <Play className="h-3.5 w-3.5" />
                  Resume
                  {heldSales.length > 0 && (
                    <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">
                      {heldSales.length}
                    </span>
                  )}
                </button>
              </div>
              <span className="text-[11px] font-semibold text-slate-400">
                F6 / F7
              </span>
            </div>

            <button
              disabled={
                !cart.length ||
                saving ||
                !options?.open_shift ||
                saleBlockedByDraft ||
                (splitPayment && secondaryDue <= 0)
              }
              onClick={() => void completeSale()}
              className={`${focusMode ? 'mt-2 py-2.5' : 'mt-3 py-3'} w-full rounded-xl bg-slate-950 px-5 text-sm font-bold text-white shadow-lg transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40`}
            >
              {saving ? 'Processing...' : `Take payment - ${money(total)} (F8)`}
            </button>
          </div>
        </aside>
      </div>

      {resumeOpen && (
        <div className="fixed bottom-5 right-5 z-[145] w-[min(360px,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl">
          <div className="mb-2 flex items-center justify-between">
            <div>
              <p className="text-xs font-black uppercase tracking-wide text-slate-900">
                Paused sales
              </p>
              <p className="text-[11px] text-slate-500">
                Resume replaces the current bill.
              </p>
            </div>
            <button
              type="button"
              onClick={() => setResumeOpen(false)}
              className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
              aria-label="Close paused sales"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
          <div className="content-scrollbar max-h-64 space-y-2 overflow-y-auto">
            {heldSales.map((sale) => (
              <button
                key={sale.id}
                onClick={() => void resumeHeldSale(sale.id)}
                disabled={saving}
                className="flex w-full items-center justify-between rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-left text-xs font-bold text-slate-900 transition hover:border-emerald-300 hover:bg-emerald-50 disabled:opacity-50"
              >
                <span>
                  <span className="block">{sale.invoice_no}</span>
                  {sale.hold_reference && (
                    <span className="mt-0.5 block text-[10px] font-black tracking-widest text-amber-700">
                      ID {sale.hold_reference}
                    </span>
                  )}
                </span>
                <span className="font-black text-emerald-700">
                  {money(sale.grand_total)}
                </span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
