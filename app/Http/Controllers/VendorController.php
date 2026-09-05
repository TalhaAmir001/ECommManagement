<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVendorPaymentRequest;
use App\Http\Requests\StoreVendorPurchaseRequest;
use App\Http\Requests\StoreVendorRequest;
use App\Http\Requests\UpdateVendorRequest;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPurchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VendorController extends Controller
{
    /**
     * List vendors with their totals and running balances.
     */
    public function index(Request $request): View
    {
        $filters = $request->query();

        $query = Vendor::query();

        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('contact_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }
        }

        $vendors = $query
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Aggregate money per vendor without an N+1.
        $vendors->loadSum('purchases', 'total_cost');
        $vendors->loadSum('payments', 'amount');

        $summary = $this->portfolioSummary();

        return view('vendors.index', [
            'vendors' => $vendors,
            'filters' => $filters,
            'summary' => $summary,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create(): View
    {
        return view('vendors.create');
    }

    /**
     * Persist a new vendor.
     */
    public function store(StoreVendorRequest $request): RedirectResponse
    {
        $vendor = Vendor::create($request->validated());

        return redirect()
            ->route('vendors.show', $vendor)
            ->with('status', "Vendor \"{$vendor->name}\" added.");
    }

    /**
     * Vendor detail: header, running balance and both history tables.
     */
    public function show(Vendor $vendor): View
    {
        $vendor->load(['purchases', 'payments']);

        $purchases = $vendor->purchases()->orderByDesc('purchase_date')->orderByDesc('id')->get();
        $payments = $vendor->payments()->orderByDesc('payment_date')->orderByDesc('id')->get();

        return view('vendors.show', [
            'vendor' => $vendor,
            'purchases' => $purchases,
            'payments' => $payments,
            'purchased' => $vendor->totalPurchased(),
            'paid' => $vendor->totalPaid(),
            'balance' => $vendor->balance(),
        ]);
    }

    /**
     * Edit form.
     */
    public function edit(Vendor $vendor): View
    {
        return view('vendors.edit', [
            'vendor' => $vendor,
        ]);
    }

    /**
     * Update an existing vendor.
     */
    public function update(UpdateVendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $vendor->update($request->validated());

        return redirect()
            ->route('vendors.show', $vendor)
            ->with('status', "Vendor \"{$vendor->name}\" updated.");
    }

    /**
     * Remove a vendor (purchases and payments cascade).
     */
    public function destroy(Vendor $vendor): RedirectResponse
    {
        $name = $vendor->name;
        $vendor->delete();

        return redirect()
            ->route('vendors.index')
            ->with('status', "Vendor \"{$name}\" deleted.");
    }

    /**
     * Record goods/raw material received from a vendor.
     */
    public function storePurchase(Vendor $vendor, StoreVendorPurchaseRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $total = round(
            (float) $validated['quantity'] * (float) $validated['unit_cost'],
            2
        );

        $vendor->purchases()->create([
            ...$validated,
            'total_cost' => $total,
        ]);

        return back()
            ->with('status', "Purchase of {$validated['item_description']} recorded against {$vendor->name}.");
    }

    /**
     * Remove a recorded purchase.
     */
    public function destroyPurchase(VendorPurchase $purchase): RedirectResponse
    {
        $purchase->delete();

        return back()->with('status', 'Purchase removed.');
    }

    /**
     * Record a payment made to a vendor.
     */
    public function storePayment(Vendor $vendor, StoreVendorPaymentRequest $request): RedirectResponse
    {
        $vendor->payments()->create($request->validated());

        return back()
            ->with('status', 'Payment of '.format_money((float) $request->validated('amount'), 2)." recorded for {$vendor->name}.");
    }

    /**
     * Remove a recorded payment.
     */
    public function destroyPayment(VendorPayment $payment): RedirectResponse
    {
        $payment->delete();

        return back()->with('status', 'Payment removed.');
    }

    /**
     * Cross-vendor totals used by the portfolio summary cards.
     *
     * @return array{purchased: float, paid: float, balance: float}
     */
    private function portfolioSummary(): array
    {
        $purchased = (float) VendorPurchase::query()->sum('total_cost');
        $paid = (float) VendorPayment::query()->sum('amount');

        return [
            'purchased' => $purchased,
            'paid' => $paid,
            'balance' => round($purchased - $paid, 2),
        ];
    }
}
