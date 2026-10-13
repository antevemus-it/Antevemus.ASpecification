<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use Throwable;

/**
 * FeatureFlagEvaluation - The Value of a Flag for a Subject, With Its Reason
 *
 * What FeatureFlags::evaluate() returns (forward 034, D7): the value, the reason (FeatureFlagReason,
 * mapped to OpenFeature), the code, the subject key that was matched (when any) and, for the reason
 * ERROR, the exception that was reported. toMetadata() is what FeatureEnabledSpecification puts in
 * the metadata of its failure.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlagEvaluation
{
    /** True when the flag is on for the subject. */
    public readonly bool $value;

    /**
     * @param string $code Flag code
     * @param FeatureFlagReason $reason Why it is on or off
     * @param string|null $subjectKey Subject key read from the subject (null = none)
     * @param Throwable|null $error The failure behind the reason ERROR
     */
    public function __construct(
        public readonly string $code,
        public readonly FeatureFlagReason $reason,
        public readonly ?string $subjectKey = null,
        public readonly ?Throwable $error = null
    ) {
        $this->value = $reason->isOn();
    }

    /**
     * Metadata of the evaluation: flag, value, reason, OpenFeature reason, and, when present, the
     * OpenFeature error code, the subject key and the class of the error (never its message, which
     * may carry data of the database).
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        $metadata = [
            'flag' => $this->code,
            'value' => $this->value,
            'reason' => $this->reason->value,
            'open_feature_reason' => $this->reason->toOpenFeatureReason(),
        ];
        if (($errorCode = $this->reason->toOpenFeatureErrorCode()) !== null) {
            $metadata['open_feature_error_code'] = $errorCode;
        }
        if ($this->subjectKey !== null) {
            $metadata['subject_key'] = $this->subjectKey;
        }
        if ($this->error !== null) {
            $metadata['error_class'] = $this->error::class;
        }

        return $metadata;
    }
}
