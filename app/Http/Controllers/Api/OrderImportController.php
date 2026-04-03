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

        $columns = ['customer_name', 'customer_phone', 'customer_email', 'total_amount', 'status', 'sale_code', 'notes'];
        $example = ['Nguyễn Văn A', '0909123456', 'nva@email.com', '1000000', 'pending', 'NV0001', 'Ghi chú'];

        $content = implode(',', $columns) . "\n" . implode(',', $example) . "\n";

        return response($content, 200, $headers);
    }
}
