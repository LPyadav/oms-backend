<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Models\User;
use App\Policies\ReportPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Validator;

abstract class ReportRequest extends FormRequest
{
    protected const string MINIMUM_DATE = '1000-01-01';

    protected const string MAXIMUM_DATE = '9999-12-31';

    public function authorize(ReportPolicy $policy): bool
    {
        $actor = $this->user();

        return $actor instanceof User && $policy->view($actor);
    }

    /** @return array<array-key, mixed> */
    public function validationData(): array
    {
        return array_replace([
            'page' => 1,
            'per_page' => Config::integer('reports.default_per_page'),
        ], $this->query->all());
    }

    /** @return array<string, list<string|Enum|RequiredIf>> */
    public function rules(): array
    {
        $maximumPerPage = Config::integer('reports.max_per_page');
        $requiresDates = $this->query->has('date_from') || $this->query->has('date_to');
        $dateRules = [
            'bail',
            Rule::requiredIf($requiresDates),
            'string',
            'date_format:Y-m-d',
            'after_or_equal:'.static::MINIMUM_DATE,
            'before_or_equal:'.static::MAXIMUM_DATE,
        ];

        return [
            'date_from' => $dateRules,
            'date_to' => [...$dateRules, 'after_or_equal:date_from'],
            'page' => ['bail', 'required', 'integer', 'min:1', 'max:'.intdiv(PHP_INT_MAX, $maximumPerPage)],
            'per_page' => ['bail', 'required', 'integer', 'min:1', 'max:'.$maximumPerPage],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [static function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $from = $data['date_from'] ?? null;
            $to = $data['date_to'] ?? null;
            if (! is_string($from) || ! is_string($to)) {
                return;
            }

            $maximumDays = Config::integer('reports.max_days');
            $lastAllowedDate = CarbonImmutable::parse($from, 'UTC')->addDays($maximumDays - 1);
            if (CarbonImmutable::parse($to, 'UTC')->greaterThan($lastAllowedDate)) {
                $validator->errors()->add(
                    'date_to',
                    Lang::string('api.reports.date_range_exceeded', ['days' => $maximumDays]),
                );
            }
        }];
    }

    /** @return array{date_from: string, date_to: string, page: int, per_page: int} */
    protected function dateAndPageFilters(): array
    {
        $from = $this->validated('date_from');
        $to = $this->validated('date_to');
        if (! is_string($from) || ! is_string($to)) {
            $today = CarbonImmutable::parse(now(Config::string('dashboard.timezone'))->toDateString(), 'UTC');
            $from = $today->subDays(Config::integer('reports.default_days') - 1)->toDateString();
            $to = $today->toDateString();
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'page' => (int) $this->validated('page'),
            'per_page' => (int) $this->validated('per_page'),
        ];
    }
}
