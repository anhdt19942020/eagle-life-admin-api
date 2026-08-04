<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrintifyShopResource;
use App\Models\PrintifyShop;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
class PrintifyShopController extends Controller { use ApiResponse; public function index() { return $this->success(PrintifyShopResource::collection(PrintifyShop::query()->orderBy('title')->paginate())); } public function confirmManualApproval(Request $request, PrintifyShop $shop) { $shop->update(['manual_approval_confirmed_by'=>$request->user()->id,'manual_approval_confirmed_at'=>now()]); return $this->success(new PrintifyShopResource($shop), 'Đã xác nhận Manual approval'); } }
