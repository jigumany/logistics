<?php

namespace App\Http\Controllers;

use App\Domain\Fleet\Vehicle;
use App\Domain\Resources\StaffDocument;
use App\Domain\Resources\StaffMember;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Http\Resources\OperationalRecord;
use App\Infrastructure\Storage\PrivateTenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OperationalMediaController extends Controller
{
    public function upload(Request $request, PrivateTenantFiles $storage, string $company, string $kind, string $record): RedirectResponse
    {
        abort_unless($request->attributes->get('membership')->role->managesCompany(), 403);
        abort_unless(in_array($kind, ['logo', 'vehicle', 'document'], true), 404);
        $file = $request->validate(['file' => ['required', 'file', 'max:5120', ...($kind === 'document' ? ['mimes:pdf,jpg,jpeg,png,webp'] : ['image', 'mimes:jpg,jpeg,png,webp'])]])['file'];
        $subject = match ($kind) {
            'logo' => CompanySettings::where('public_id', $record)->firstOrFail(),'vehicle' => Vehicle::where('public_id', $record)->firstOrFail(),'document' => StaffMember::where('public_id', $record)->firstOrFail()
        };
        $path = $storage->store($file, $kind);
        $old = null;
        try {
            DB::transaction(function () use ($subject, $kind, $file, $path, $request, &$old): void {
                $subject = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
                if ($kind === 'document') {
                    StaffDocument::create(['staff_id' => $subject->id, 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize()]);
                } else {
                    $field = $kind === 'logo' ? 'logo_path' : 'photo_path';
                    $old = $subject->getAttribute($field);
                    $subject->setAttribute($field, $path);
                    $subject->save();
                }
                app(RecordsAdministrativeChange::class)->record($subject, 'media.attached', $request->user()->id);
            });
        } catch (\Throwable $error) {
            try {
                $storage->delete($path);
            } catch (\Throwable $cleanupError) {
                report($cleanupError);
            }
            throw $error;
        }
        if ($old) {
            try {
                $storage->delete($old);
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return back()->with('success', 'Private file uploaded.');
    }

    public function index(Request $request, string $company, string $record): JsonResponse
    {
        abort_unless($request->attributes->get('membership')->role->managesCompany(), 403);
        $staff = StaffMember::where('public_id', $record)->firstOrFail();

        return response()->json(['documents' => StaffDocument::where('staff_id', $staff->id)->orderByDesc('id')->paginate(20)->through(fn ($row) => OperationalRecord::make($row))]);
    }

    public function download(Request $request, PrivateTenantFiles $storage, string $company, string $kind, string $record): StreamedResponse
    {
        if ($kind === 'document') {
            abort_unless($request->attributes->get('membership')->role->managesCompany(), 403);
            $doc = StaffDocument::where('public_id', $record)->firstOrFail();

            return $storage->download($doc->path, $doc->original_name);
        }
        $subject = match ($kind) {
            'logo' => CompanySettings::where('public_id', $record)->firstOrFail(),'vehicle' => Vehicle::where('public_id', $record)->firstOrFail(),default => abort(404)
        };
        $path = $subject->getAttribute($kind === 'logo' ? 'logo_path' : 'photo_path');
        abort_unless($path, 404);

        return $storage->download($path, basename($path));
    }
}
