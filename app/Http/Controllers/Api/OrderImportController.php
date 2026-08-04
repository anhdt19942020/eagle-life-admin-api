<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OrderImportService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderImportController extends Controller
{
    use ApiResponse;

    public function __construct(private OrderImportService $importService) {}

    public function import(Request $request)
    {
        $request->validate([
            'orders' => 'required|array|min:1',
            'orders.*.ebay_order_id' => 'required|string',
            'orders.*.ebay_created_at' => 'required|string',
            'orders.*.buyer_code' => 'nullable|string',
            'orders.*.seller_code' => 'nullable|string',
        ]);

        $result = $this->importService->importFromArray($request->orders);

        $message = "Import hoàn tất: {$result['success']}/{$result['total']} thành công";

        return $this->success($result, $message);
    }

    public function importCsv(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $result = $this->importService->importFromCsv($file, $request->user()?->id);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($result, 'Import CSV hoàn tất');
    }

    public function template(): StreamedResponse
    {
        $headers = array_merge(
            OrderImportService::REQUIRED_CSV_HEADERS,
            OrderImportService::OPTIONAL_CSV_HEADERS,
        );

        return response()->streamDownload(function () use ($headers): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            fputcsv($handle, [
                '13-14975-00010',
                'Aug-02-26',
                '123456789012',
                '1',
                '$10.00',
                '$0.00',
                '$10.00',
                'Jane Doe',
                '1 Main St',
                'Austin',
                '78701',
                'US',
                'T-1',
                'Example Shirt',
                '',
                '[Size:M]',
                '',
                '',
                'TX',
            ]);
            fclose($handle);
        }, 'ebay_order_import_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
