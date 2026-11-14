<?php

namespace App\Support\JobQualification;

use App\Enums\QualificationVerdict;
use App\Exceptions\InvalidJobQualificationResponseException;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;

/**
 * The one authoritative check on a decoded screening response: exact keys, types, every enum value, every size bound, and
 * every evidence id against the segment ids actually supplied. The JSON schema sent to the provider says the same things,
 * but how strictly a provider enforces a schema is not something to rely on; this is what decides.
 */
final class JobQualificationResponseValidator
{
    public const ROLE_TYPES = ['product_engineering', 'frontend', 'backend', 'full_stack', 'data_engineering', 'infrastructure_devops', 'engineering_management', 'other_engineering', 'non_engineering'];

    public const HANDS_ON = ['hands_on', 'mixed', 'primarily_management', 'unclear'];

    public const REQUIREMENT = ['mandatory', 'preferred', 'incidental'];

    public const FAMILIARITY = ['familiar', 'related', 'unfamiliar'];

    public const DEPTH = ['deep_production', 'working', 'unstated'];

    public const SENIORITY = ['junior', 'mid', 'senior', 'staff_or_above', 'unspecified'];

    public const MAX_TECHNOLOGIES = 12;

    public const MAX_RESPONSIBILITIES = 5;

    public const MAX_FIT_REASONS = 3;

    public const MAX_PRACTICAL_CONCERNS = 3;

    public const MAX_EVIDENCE_REFS = 2;

    public const TOP_LEVEL_KEYS = [
        'verdict', 'role_type', 'primary_role_focus', 'hands_on', 'core_responsibilities', 'technologies', 'seniority',
        'fit_reasons', 'biggest_concern', 'biggest_concern_evidence_refs', 'management_evidence_refs', 'practical_concerns',
    ];

    /**
     * @param  array<string, mixed>  $content  Untrusted, decoded provider output.
     * @param  list<string>  $validSegmentIds
     * @return array<string, mixed> Exactly the validated fields.
     *
     * @throws InvalidJobQualificationResponseException
     */
    public function validate(array $content, array $validSegmentIds): array
    {
        $extra = array_diff(array_keys($content), self::TOP_LEVEL_KEYS);
        if ($extra !== []) {
            throw new InvalidJobQualificationResponseException('Job Qualification response had unexpected fields: '.implode(', ', $extra).'.');
        }

        $refs = ['array', 'max:'.self::MAX_EVIDENCE_REFS];
        $validator = ValidatorFacade::make($content, [
            'verdict' => ['required', 'string', Rule::in(array_column(QualificationVerdict::cases(), 'value'))],
            'role_type' => ['required', 'string', Rule::in(self::ROLE_TYPES)],
            'primary_role_focus' => ['required', 'string', 'min:3', 'max:200'],
            'hands_on' => ['required', 'string', Rule::in(self::HANDS_ON)],
            'core_responsibilities' => ['required', 'array', 'min:1', 'max:'.self::MAX_RESPONSIBILITIES],
            'core_responsibilities.*' => ['required', 'string', 'min:3', 'max:200'],
            'technologies' => ['present', 'array', 'max:'.self::MAX_TECHNOLOGIES],
            'technologies.*' => ['array:name,requirement,familiarity,depth,central,transferable_accepted,evidence_refs'],
            'technologies.*.name' => ['required', 'string', 'min:1', 'max:80'],
            'technologies.*.requirement' => ['required', 'string', Rule::in(self::REQUIREMENT)],
            'technologies.*.familiarity' => ['required', 'string', Rule::in(self::FAMILIARITY)],
            'technologies.*.depth' => ['required', 'string', Rule::in(self::DEPTH)],
            'technologies.*.central' => ['required', 'boolean'],
            'technologies.*.transferable_accepted' => ['required', 'boolean'],
            'technologies.*.evidence_refs' => ['required', 'min:1', ...$refs],
            'technologies.*.evidence_refs.*' => ['required', 'string', Rule::in($validSegmentIds)],
            'seniority' => ['required', 'string', Rule::in(self::SENIORITY)],
            'fit_reasons' => ['present', 'array', 'max:'.self::MAX_FIT_REASONS],
            'fit_reasons.*' => ['array:text,evidence_refs'],
            'fit_reasons.*.text' => ['required', 'string', 'min:3', 'max:240'],
            'fit_reasons.*.evidence_refs' => ['required', 'min:1', ...$refs],
            'fit_reasons.*.evidence_refs.*' => ['required', 'string', Rule::in($validSegmentIds)],
            'biggest_concern' => ['present', 'nullable', 'string', 'max:240'],
            'biggest_concern_evidence_refs' => ['present', ...$refs],
            'biggest_concern_evidence_refs.*' => ['required', 'string', Rule::in($validSegmentIds)],
            'management_evidence_refs' => ['present', ...$refs],
            'management_evidence_refs.*' => ['required', 'string', Rule::in($validSegmentIds)],
            'practical_concerns' => ['present', 'array', 'max:'.self::MAX_PRACTICAL_CONCERNS],
            'practical_concerns.*' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        if ($validator->fails()) {
            // Field paths only: never echo the offending values back into logs or the database.
            throw new InvalidJobQualificationResponseException('Job Qualification response failed validation: '.implode(', ', array_keys($validator->errors()->messages())).'.');
        }

        // A recommendation that is not strong must say what it rests on; a stated concern must be supported.
        if (($content['biggest_concern'] ?? null) !== null && ($content['biggest_concern_evidence_refs'] ?? []) === []) {
            throw new InvalidJobQualificationResponseException('Job Qualification response failed validation: biggest_concern_evidence_refs.');
        }

        if ($content['verdict'] !== QualificationVerdict::Poor->value && ($content['fit_reasons'] ?? []) === []) {
            throw new InvalidJobQualificationResponseException('Job Qualification response failed validation: fit_reasons.');
        }

        return array_intersect_key($content, array_flip(self::TOP_LEVEL_KEYS));
    }
}
