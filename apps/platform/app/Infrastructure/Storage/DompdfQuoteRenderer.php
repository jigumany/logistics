<?php

namespace App\Infrastructure\Storage;

use App\Domain\Pricing\DescribePriceLine;
use App\Domain\Quotes\QuotationDocumentRenderer;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Tenancy\CompanySettings;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DompdfQuoteRenderer implements QuotationDocumentRenderer
{
    public function render(Quote $quote, QuoteRevision $revision): string
    {
        $settings = CompanySettings::firstOrFail();
        $logo = null;
        if ($settings->logo_path && Storage::exists($settings->logo_path) && Storage::size($settings->logo_path) <= 5242880) {
            $mime = Storage::mimeType($settings->logo_path);
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                $bytes = Storage::get($settings->logo_path);
                $dimensions = @getimagesizefromstring($bytes);
                if ($dimensions && $dimensions[0] * $dimensions[1] <= 16777216) {
                    $logo = 'data:'.$mime.';base64,'.base64_encode($bytes);
                }
            }
        }
        $directory = storage_path('framework/cache/dompdf');
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the private PDF rendering directory.');
        }
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'fontCache' => $directory, 'tempDir' => $directory, 'chroot' => public_path()]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $rules = collect($revision->pricing_snapshot['rules'] ?? [])->keyBy('code');
        $lineMetadata = $revision->lines->mapWithKeys(fn ($line) => [$line->code => app(DescribePriceLine::class)->execute($rules->get($line->code, ['rate_micros' => $line->rate_micros]), $revision->currency ?? '')])->all();
        $pdf->loadHtml(view('quotes.pdf', ['quote' => $quote, 'revision' => $revision, 'settings' => (object) $revision->branding_snapshot, 'logo' => $logo, 'lineMetadata' => $lineMetadata])->render(), 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }
}
