<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\CatalogueItem;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteAttachment;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Storage\PrivateTenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class QuoteMediaController extends Controller
{
    public function upload(Request $request, string $company, string $quote, PrivateTenantFiles $storage): JsonResponse
    {
        $model = app(GuestQuoteController::class)->find($quote);
        $data = $request->validate(['item_key' => ['required', 'uuid'], 'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $file = $data['file'];
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = QuoteAttachment::where('quote_id', $model->id)->where('item_key', $data['item_key'])->where('sha256', $hash)->first();
        if ($existing) {
            return response()->json($existing->only('public_id', 'item_key'));
        }
        $path = $storage->store($file, 'quotes/'.$model->public_id.'/photos');
        try {
            $attachment = DB::transaction(function () use ($model, $data, $path, $file, $hash): QuoteAttachment {
                $quote = Quote::whereKey($model->id)->lockForUpdate()->firstOrFail();
                if ($quote->status !== 'draft' || $quote->current_revision_id || ! in_array($data['item_key'], array_column($quote->draft_input['inventory'], 'item_key'), true)) {
                    throw ValidationException::withMessages(['file' => 'Photos can be attached to inventory before calculating the initial request.']);
                }
                if (QuoteAttachment::where('quote_id', $quote->id)->count() >= 50 || QuoteAttachment::where('quote_id', $quote->id)->where('item_key', $data['item_key'])->count() >= 5) {
                    throw ValidationException::withMessages(['file' => 'Attach at most five photos per item and fifty per request.']);
                }

                return QuoteAttachment::firstOrCreate(['quote_id' => $quote->id, 'item_key' => $data['item_key'], 'sha256' => $hash], ['path' => $path, 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize()]);
            });
        } catch (Throwable $failure) {
            $storage->delete($path);
            throw $failure;
        }
        if ($attachment->path !== $path) {
            $storage->delete($path);
        }

        return response()->json($attachment->only('public_id', 'item_key'), 201);
    }

    public function guest(string $company, string $quote, string $attachment, PrivateTenantFiles $storage): StreamedResponse
    {
        $model = app(GuestQuoteController::class)->find($quote);
        $file = QuoteAttachment::where('public_id', $attachment)->where('quote_id', $model->id)->firstOrFail();

        return $storage->download($file->path, 'inventory-photo.'.match ($file->mime_type) {
            'image/png' => 'png','image/webp' => 'webp',default => 'jpg'
        });
    }

    public function catalogueUpload(Request $request, string $company, string $item, PrivateTenantFiles $storage): RedirectResponse
    {
        app(QuotePermissions::class)->manage($request->user()->id);
        $model = CatalogueItem::where('public_id', $item)->where('tenant_id', app(TenantContext::class)->tenant()->id)->firstOrFail();
        $data = $request->validate(['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $path = $storage->store($data['file'], 'inventory');
        $old = null;
        try {
            DB::transaction(function () use ($model, $path, &$old, $request): void {
                $model = CatalogueItem::whereKey($model->id)->lockForUpdate()->firstOrFail();
                $old = $model->image_path;
                $model->image_path = $path;
                $model->save();
                app(RecordsAdministrativeChange::class)->record($model, 'inventory_catalogue.image_saved', $request->user()->id);
            });
        } catch (Throwable $failure) {
            $storage->delete($path);
            throw $failure;
        }
        if ($old) {
            $storage->delete($old);
        }
        Cache::increment('catalogue_version:'.app(TenantContext::class)->tenant()->id);

        return back()->with('success', 'Catalogue image saved.');
    }

    public function catalogueImage(string $company, string $item, PrivateTenantFiles $storage): StreamedResponse
    {
        $model = CatalogueItem::where('public_id', $item)->where('active', true)->firstOrFail();
        abort_unless($model->image_path !== null && $model->image_path !== '', 404);
        if ($model->tenant_id === null) {
            abort_unless(str_starts_with($model->image_path, 'catalogue/global/'), 404);

            return Storage::download($model->image_path, 'catalogue-image', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        return $storage->download($model->image_path, 'catalogue-image');
    }
}
