<?php

namespace App\Domain\Payments;

interface CheckoutGateway
{
    public function create(PaymentAttempt $attempt): array;
}
