<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderImportService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class OrderImportController extends Controller
{
    use ApiResponse;

    public function __construct(private OrderImportService $importService) {}

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240', // max 10MB
        ]);

        $file = $request->file('file');
        $filePath = $file->getRealPath();

        try {
            $result = $this->importService->import($filePath);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $message = "Import hoàn tất: {$result['success']}/{$result['total']} thành công";

        return $this->success($result, $message);
    }

    public function template()
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="order_import_template.csv"',
        ];

        $columns = ['ebay_order_id', 'ebay_created_at', 'buyer_code', 'seller_code', 'printify_order_id', 'printify_created_at'];
        $example = ['EB-123456789', '2026-04-01 08:30:00', 'NV0002', 'NV0001', '', ''];

        $content = implode(',', $columns) . "\n" . implode(',', $example) . "\n";

        return response($content, 200, $headers);
    }
}
