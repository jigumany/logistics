<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Integration');

require_once __DIR__.'/Support/QuotationFixtures.php';
