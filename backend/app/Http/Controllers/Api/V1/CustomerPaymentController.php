<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TCustomerPayment;
use App\Services\SalesService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerPaymentController extends BaseController
{
    public function __construct(private readonly SalesService $salesService)
    {
    }

    public function index(Request $request)
    {
        $payments = TCustomerPayment::query()
            ->where('BC', $request->user()->BC)
            ->with(['customer', 'allocations.invoice', 'bankAccount'])
            ->when($request->query('search'), function ($query, string $search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('Payment_no', 'like', "%{$search}%")
                        ->orWhere('Customer_Name', 'like', "%{$search}%")
                        ->orWhere('Customer_NIC', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('Payment_date')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return $this->paginatedResponse($payments, 'Customer payments retrieved successfully.');
    }

    public function outstanding(Request $request)
    {
        $validated = $request->validate([
            'customer_code' => [
                'required',
                'string',
                Rule::exists('customers', 'Code')->where('BC', $request->user()->BC),
            ],
        ]);

        return $this->successResponse(
            $this->salesService->customerOutstanding(
                $validated['customer_code'],
                $request->user()->BC
            ),
            'Customer outstanding invoices retrieved successfully.'
        );
    }

    public function store(Request $request)
    {
        $payment = $this->salesService->createCustomerPayment(
            $this->validatePayment($request),
            $request->user()
        );

        return $this->successResponse($payment, 'Customer payment posted successfully.', 201);
    }

    public function show(Request $request, int $customerPayment)
    {
        $payment = TCustomerPayment::query()
            ->whereKey($customerPayment)
            ->where('BC', $request->user()->BC)
            ->with(['customer', 'allocations.invoice', 'bankAccount'])
            ->firstOrFail();

        return $this->successResponse($payment);
    }

    private function validatePayment(Request $request): array
    {
        $branchCode = $request->user()->BC;

        return $request->validate([
            'payment_date' => ['required', 'date'],
            'customer_code' => [
                'required',
                'string',
                Rule::exists('customers', 'Code')->where('BC', $branchCode),
            ],
            'payment_note' => ['nullable', 'string', 'max:2000'],
            'payment_amount' => ['required', 'numeric', 'min:0.01'],
            'cash_payment' => ['nullable', 'numeric', 'min:0'],
            'card_payment' => ['nullable', 'numeric', 'min:0'],
            'cheque_payment' => ['nullable', 'numeric', 'min:0'],
            'bank_transfer' => ['nullable', 'numeric', 'min:0'],
            'bank_detail_id' => ['nullable', 'integer'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.sales_invoice_no' => [
                'required',
                'string',
                'distinct',
                Rule::exists('t_invoice_sums', 'Invoice_no')->where('BC', $branchCode),
            ],
            'allocations.*.amount' => ['required', 'numeric', 'min:0.01'],
            'cheque' => ['nullable', 'array'],
            'cheque.bank_id' => ['nullable', 'integer'],
            'cheque.bank_branch_id' => ['nullable', 'integer'],
            'cheque.cheque_no' => ['nullable', 'string', 'max:50'],
            'cheque.account_no' => ['nullable', 'string', 'max:50'],
            'cheque.due_date' => ['nullable', 'date'],
        ]);
    }
}
