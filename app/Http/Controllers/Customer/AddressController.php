<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Webkul\Customer\Repositories\CustomerAddressRepository;

class AddressController extends Controller
{
    protected $customerAddressRepository;

    public function __construct(CustomerAddressRepository $customerAddressRepository)
    {
        $this->customerAddressRepository = $customerAddressRepository;
    }

    public function index()
    {
        $customer = auth()->guard('customer')->user();

        $ordersCount = \DB::table('orders')
            ->where('customer_id', $customer->id)
            ->where('is_guest', 0)
            ->count();

        $addresses = $this->customerAddressRepository->findWhere([
            'customer_id' => $customer->id
        ]);

        return view('customers.account.addresses', compact('addresses', 'ordersCount'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'address1' => 'required|string|max:255',
            'address2' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:2',
            'phone' => 'nullable|string|max:20'
        ]);

        $customer = auth()->guard('customer')->user();

        $address = $this->customerAddressRepository->create([
            'customer_id' => $customer->id,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'company_name' => $validated['company'] ?? null,
            'address' => json_encode([$validated['address1']]),
            'city' => $validated['city'],
            'state' => $validated['state'],
            'postcode' => $validated['postal_code'],
            'country' => $validated['country'],
            'phone' => $validated['phone'] ?? null,
            'default_address' => 0
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Address saved successfully!',
            'address' => $address
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'address1' => 'required|string|max:255',
            'address2' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:2',
            'phone' => 'nullable|string|max:20'
        ]);

        $customer = auth()->guard('customer')->user();

        $address = $this->customerAddressRepository->findOneWhere([
            'id' => $id,
            'customer_id' => $customer->id
        ]);

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Address not found'
            ], 404);
        }

        $address = $this->customerAddressRepository->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'company_name' => $validated['company'] ?? null,
            'address' => json_encode([$validated['address1']]),
            'city' => $validated['city'],
            'state' => $validated['state'],
            'postcode' => $validated['postal_code'],
            'country' => $validated['country'],
            'phone' => $validated['phone'] ?? null,
            'default_address' => 0
        ], $id);

        return response()->json([
            'status' => 'success',
            'message' => 'Address updated successfully!',
            'address' => $address
        ]);
    }

    public function destroy($id)
    {
        $customer = auth()->guard('customer')->user();

        $address = $this->customerAddressRepository->findOneWhere([
            'id' => $id,
            'customer_id' => $customer->id
        ]);

        if (!$address) {
            return response()->json([
                'status' => 'error',
                'message' => 'Address not found'
            ], 404);
        }

        $this->customerAddressRepository->delete($id);

        return response()->json([
            'status' => 'success',
            'message' => 'Address deleted successfully!'
        ]);
    }
}
