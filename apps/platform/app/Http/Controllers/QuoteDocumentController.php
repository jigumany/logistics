<?php

namespace App\Http\Controllers;

use App\Domain\Quotes\GenerateQuotePdf;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteDocument;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Storage\PrivateTenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class QuoteDocumentController extends Controller
{
    public function guest(string $company, string $quote): StreamedResponse|JsonResponse
    {
        return $this->download(app(GuestQuoteController::class)->find($quote));
    }

    public function internal(Request $request, string $company, string $quote): StreamedResponse|JsonResponse
    {
        abort_unless(in_array($request->attributes->get('membership')?->role->value, ['owner', 'admin', 'dispatcher'], true), 403);

        return $this->download(Quote::where('public_id', $quote)->firstOrFail());
    }

    private function download(Quote $quote): StreamedResponse|JsonResponse
    {
        abort_if($quote->simulation_price_book_id !== null, 404);
        abort_unless($quote->submitted_at && $quote->current_revision_id, 404);
        $document = QuoteDocument::where('revision_id', $quote->current_revision_id)->first();
        if (! $document) {
            $quote->loadMissing('currentRevision');
            GenerateQuotePdf::dispatch(app(TenantContext::class)->tenant()->id, $quote->currentRevision->public_id)->afterCommit();

            return response()->json(['message' => 'Your quotation PDF is being prepared. Please try again shortly.'], 202);
        }

        return app(PrivateTenantFiles::class)->download($document->path, $quote->reference.'.pdf');
    }
}
