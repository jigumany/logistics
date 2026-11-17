<?php

namespace App\Domain\Quotes;

final class QuotationTerms
{
    public const SECTIONS = ['insurance_information', 'special_handling', 'customer_responsibilities', 'access_parking', 'cancellation', 'rescheduling', 'damage_reporting', 'additional_charges'];

    public function snapshot(QuotationPolicy $policy): array
    {
        $config = $policy->commercial_terms ?? [];
        $text = $config !== [] && ($config['status'] ?? '') !== 'reviewed' ? '' : ($policy->terms ?? '');
        foreach (self::SECTIONS as $field) {
            if (($config['status'] ?? '') !== 'reviewed' || ($field === 'insurance_information' && ($config['insurance_status'] ?? '') !== 'reviewed') || ! filled($config[$field] ?? null)) {
                continue;
            }
            $text .= "\n\n".ucfirst(str_replace('_', ' ', $field)).":\n".$config[$field];
        }

        return $this->record($text, $policy->exclusions ?? '', $policy->terms_version ?? 'legacy', $config['status'] ?? 'draft');
    }

    public function record(string $terms, string $exclusions, string $version, string $status = 'reviewed'): array
    {
        return ['version' => $version, 'status' => $status, 'terms' => $terms, 'exclusions' => $exclusions, 'hash' => hash('sha256', json_encode([$version, $terms, $exclusions], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
    }

    public function reviewed(QuotationPolicy $policy): bool
    {
        $terms = $policy->commercial_terms ?? [];

        return filled($policy->terms) && filled($policy->terms_version) && ($terms['status'] ?? '') === 'reviewed' && ($terms['insurance_status'] ?? '') === 'reviewed' && collect(self::SECTIONS)->every(fn ($field) => filled($terms[$field] ?? null));
    }
}
