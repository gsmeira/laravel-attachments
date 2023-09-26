<?php

namespace GSMeira\LaravelAttachments\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignedStorageUrlController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $uuid = (string) Str::uuid();

        $tempFolder = trim(config('attachments.signed_storage.temp_folder'), '/');

        $path = "$tempFolder/$uuid";

        $data = Storage::temporaryUploadUrl(
            $path,
            now()->addMinutes(config('attachments.signed_storage.expire_after')),
            array_filter([
                'ACL' => $request->input('visibility') ?: $this->defaultVisibility(),
                'ContentType' => $request->input('content_type') ?: $this->defaultContentType(),
                'CacheControl' => $request->input('cache_control') ?: null,
                'Expires' => $request->input('expires') ?: null,
                'Bucket' => $request->input('bucket') ?: $_ENV['AWS_BUCKET'],
            ])
        );

        return response()->json([
            ...$data,
            ...compact('path'),
        ], 201);
    }

    protected function defaultVisibility(): string
    {
        return 'private';
    }

    protected function defaultContentType(): string
    {
        return 'application/octet-stream';
    }
}
