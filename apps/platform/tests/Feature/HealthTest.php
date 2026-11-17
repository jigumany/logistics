<?php

use Illuminate\Support\Facades\Redis;

it('returns a generic readiness response when dependencies are available', function (): void {
    Redis::shouldReceive('connection')->once()->andReturnSelf();
    Redis::shouldReceive('ping')->once()->andReturn('PONG');
    $this->getJson('/health/ready')->assertOk()->assertExactJson(['status' => 'ready'])->assertHeader('X-Content-Type-Options', 'nosniff');
});
it('does not leak infrastructure exception details', function (): void {
    Redis::shouldReceive('connection')->once()->andThrow(new RuntimeException('private credentials and host'));
    $this->getJson('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
});
