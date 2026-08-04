<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Resources\PrintifyProductResource;
use App\Models\PrintifyProduct;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
class PrintifyProductController extends Controller { use ApiResponse; public function index(Request $request) { $request->validate(['shop_id'=>['required','integer','exists:printify_shops,id']]); return $this->success(PrintifyProductResource::collection(PrintifyProduct::where('printify_shop_id',$request->integer('shop_id'))->with('variants')->orderBy('title')->paginate())); } }
