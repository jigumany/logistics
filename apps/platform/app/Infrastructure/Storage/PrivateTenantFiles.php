<?php

namespace App\Infrastructure\Storage;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivateTenantFiles
{
    public function store(UploadedFile $file, string $directory): string
    {
        return $file->store('tenants/'.app(TenantContext::class)->tenant()->public_id.'/'.$directory, ['visibility' => 'private']);
    }

    public function delete(string $path): void
    {
        Storage::delete($path);
    }

    public function download(string $path, string $name): StreamedResponse
    {
        abort_unless(str_starts_with($path, 'tenants/'.app(TenantContext::class)->tenant()->public_id.'/'), 404);

        return Storage::download($path, $name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
