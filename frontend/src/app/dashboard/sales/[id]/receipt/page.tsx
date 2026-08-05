import ReceiptView from './receipt-view';

export default async function ReceiptPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <ReceiptView saleId={id} />;
}
