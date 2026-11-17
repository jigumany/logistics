<!doctype html><html lang="en"><body style="font-family:Arial,sans-serif;color:#163c38;max-width:600px;margin:32px auto;padding:24px">
<h1>{{ $business }}</h1><h2>Quotation {{ $reference }}</h2>
<p>{{ match($eventType) { 'quote.review_requested' => 'Your request has been received and is awaiting an operations review.', 'quote.information_requested' => 'We need a little more information to prepare your quotation.', 'quote.revised' => 'A revised quotation is ready for your review.', default => 'Your quotation is ready for review.' } }}</p>
@if($customerMessage)<p>{{ $customerMessage }}</p>@endif
<p><a href="{{ $accessUrl }}" style="display:inline-block;padding:12px 20px;background:#115e59;color:white;text-decoration:none">View your quotation</a></p>
<p>This private link expires. Keep it safe and share it only with people you authorize to view and accept this quotation.</p>
<p>Accepting a quotation does not reserve a vehicle or confirm a booking.</p>
</body></html>