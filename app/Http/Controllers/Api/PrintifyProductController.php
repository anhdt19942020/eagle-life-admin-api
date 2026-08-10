<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrintifyProductResource;
use App\Models\PrintifyProduct;
use App\Models\PrintifyShop;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PrintifyProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $request->validate([
            'shop_id' => ['required', 'integer', 'exists:printify_shops,id'],
        ]);

        $shopId = $request->integer('shop_id');
        $user = $request->user();

        if (! $user->hasRole('admin') && ! $user->printifyShops->contains('id', $shopId)) {
            return $this->error('You do not have the required authorization.', 403);
        }

        $shop = PrintifyShop::with('account')->findOrFail($shopId);
        if ($shop->account === null || ! $shop->account->is_active) {
            return $this->error(
                'Printify account của shop này hiện không hoạt động.',
                422,
                ['code' => 'printify_account_inactive']
            );
        }

        return $this->success(
            PrintifyProductResource::collection(
                PrintifyProduct::where('printify_shop_id', $shopId)
                    ->with('variants')
                    ->orderBy('title')
                    ->paginate()
            )
        );
    }
}
