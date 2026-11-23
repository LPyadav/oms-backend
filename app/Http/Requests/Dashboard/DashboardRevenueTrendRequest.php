<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use App\Enums\DashboardRevenueGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\RequiredIf;
use Illuminate\Validation\Validator;

final class DashboardRevenueTrendRequest extends DashboardRequest
{
    /** @return array<array-key, mixed> */
    public function validationData(): array
    {
        return array_replace(['group_by' => DashboardRevenueGroup::Day->value], $this->query->all());
    }

    /** @return array<string, list<string|Enum|RequiredIf>> */
    public function rules(): array
    {
        $hasDate = $this->query->has('date_from') || $this->query->has('date_to');
        $dateRules = [
            'bail',
            Rule::requiredIf($hasDate),
            'string',
            'date_format:Y-m-d',
            'after_or_equal:1000-01-02',
            'before_or_equal:9999-12-30',
        ];

        return [
            'date_from' => $dateRules,
            'date_to' => [...$dateRules, 'after_or_equal:date_from'],
            'group_by' => ['bail', 'required', 'string', Rule::enum(DashboardRevenueGroup::class)],
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

            $maximum = Config::integer('dashboard.revenue_max_days');
            $lastAllowedDate = CarbonImmutable::parse($from, 'UTC')->addDays($maximum - 1);
            if (CarbonImmutable::parse($to, 'UTC')->greaterThan($lastAllowedDate)) {
                $validator->errors()->add(
                    'date_to',
                    Lang::string('api.dashboard.revenue_range_exceeded', ['days' => $maximum]),
                );
            }
        }];
    }

    public function group(): DashboardRevenueGroup
    {
        return DashboardRevenueGroup::from($this->validated('group_by'));
    }

    /**
     * UTC midnights represent the requested calendar dates.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function dateRange(): array
    {
        $from = $this->validated('date_from');
        $to = $this->validated('date_to');

        if (is_string($from) && is_string($to)) {
            return [
                CarbonImmutable::parse($from, 'UTC'),
                CarbonImmutable::parse($to, 'UTC'),
            ];
        }

        $localDate = now(Config::string('dashboard.timezone'))->toDateString();
        $today = CarbonImmutable::parse($localDate, 'UTC');

        return [$today->subDays(Config::integer('dashboard.revenue_default_days') - 1), $today];
    }
}
