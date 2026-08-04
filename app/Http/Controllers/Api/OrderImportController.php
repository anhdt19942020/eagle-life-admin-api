<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderImportService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class OrderImportController extends Controller
{
    use ApiResponse;

    public function __construct(private OrderImportService $importService) {}

    public function import(Request $request)
    {
        $request->validate([
            'orders'                        => 'required|array|min:1',
            'orders.*.ebay_order_id'        => 'required|string',
            'orders.*.ebay_created_at'      => 'required|string',
            'orders.*.buyer_code'           => 'nullable|string',
            'orders.*.seller_code'          => 'nullable|string',
        ]);

        $result = $this->importService->importFromArray($request->orders);

        $message = "Import hoàn tất: {$result['success']}/{$result['total']} thành công";

        return $this->success($result, $message);
    }

    public function importCsv(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv', 'max:10240']]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $result = $this->importService->importFromCsv($file, $request->user()?->id);

        return $this->success($result, 'Import CSV hoàn tất');
    }
}
