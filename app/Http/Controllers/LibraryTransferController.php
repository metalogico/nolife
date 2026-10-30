<?php

namespace App\Http\Controllers;

use App\Services\LibraryTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LibraryTransferController extends Controller
{
    public function export(LibraryTransfer $transfer): JsonResponse
    {
        set_time_limit(300);
        $id = $transfer->export();

        return response()->json([
            'download_url' => route('api.library-transfer.download', ['id' => $id], false),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function download(string $id, LibraryTransfer $transfer): BinaryFileResponse
    {
        $path = $transfer->exportPath($id);
        abort_unless(is_file($path), 404);

        return response()->download($path, 'nolife-library-'.now()->format('Ymd-His').'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate()->deleteFileAfterSend();
    }

    public function import(Request $request, LibraryTransfer $transfer): JsonResponse
    {
        $request->validate([
            'archive' => ['required', 'file', 'max:1048576'],
            'confirm' => ['required', 'accepted'],
        ]);
        set_time_limit(300);
        $result = $transfer->import($request->file('archive')->getRealPath());

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }
}
