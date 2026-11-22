<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Services\Audit\AuditLogWriter;
use App\Services\Catalog\CatalogWriteTransaction;
use App\Services\Users\AdminUserWriteLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use RuntimeException;

final readonly class CustomerService
{
    private const array PROFILE_FIELDS = ['name', 'email', 'phone', 'address'];

    public function __construct(
        private CustomerPolicy $customerPolicy,
        private AdminUserWriteLock $writeLock,
        private AuditLogWriter $auditLogWriter,
        private CatalogWriteTransaction $writeTransaction,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(
        User $actor,
        ?string $search,
        ?bool $isActive,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        if (! $this->customerPolicy->viewAny($actor)) {
            throw new AuthorizationException;
        }

        $query = Customer::query();

        if ($search !== null) {
            $pattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(static function (Builder $query) use ($pattern): void {
                $query->whereRaw("name like ? escape '!'", [$pattern])
                    ->orWhereRaw("email like ? escape '!'", [$pattern])
                    ->orWhereRaw("phone like ? escape '!'", [$pattern]);
            });
        }

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->orderBy('id')->paginate(perPage: $perPage, page: $page);
    }

    public function find(User $actor, int|string $customerId): Customer
    {
        if (! $this->customerPolicy->view($actor)) {
            throw new AuthorizationException;
        }

        return Customer::query()->findOrFail($customerId);
    }

    /**
     * @param  array{name: string, email?: string|null, phone?: string|null, address?: string|null}  $attributes
     */
    public function create(User $actor, array $attributes): Customer
    {
        return $this->writeTransaction->run(function () use ($actor, $attributes): Customer {
            $lockedActor = $this->writeLock->acquireShared($actor);

            if (! $this->customerPolicy->create($lockedActor)) {
                throw new AuthorizationException;
            }

            $customer = new Customer(Arr::only($attributes, self::PROFILE_FIELDS));
            $this->save($customer);
            $customer->refresh();

            $this->auditLogWriter->record(
                $lockedActor,
                $customer,
                'customer',
                'customer.created',
                null,
                $customer->only([...self::PROFILE_FIELDS, 'is_active']),
            );

            return $customer;
        });
    }

    /**
     * @param  array{name?: string, email?: string|null, phone?: string|null, address?: string|null}  $attributes
     */
    public function update(User $actor, int|string $customerId, array $attributes): Customer
    {
        return $this->writeTransaction->run(function () use ($actor, $customerId, $attributes): Customer {
            $lockedActor = $this->writeLock->acquireShared($actor);

            if (! $this->customerPolicy->update($lockedActor)) {
                throw new AuthorizationException;
            }

            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);
            $customer->fill(Arr::only($attributes, self::PROFILE_FIELDS));
            $changedFields = array_keys($customer->getDirty());

            if ($changedFields === []) {
                return $customer;
            }

            $before = Arr::only($customer->getOriginal(), $changedFields);
            $this->save($customer);

            $this->auditLogWriter->record(
                $lockedActor,
                $customer,
                'customer',
                'customer.updated',
                $before,
                $customer->only($changedFields),
            );

            return $customer;
        });
    }

    public function deactivate(User $actor, int|string $customerId): Customer
    {
        return $this->writeTransaction->run(function () use ($actor, $customerId): Customer {
            $lockedActor = $this->writeLock->acquireShared($actor);

            if (! $this->customerPolicy->deactivate($lockedActor)) {
                throw new AuthorizationException;
            }

            $customer = Customer::query()->lockForUpdate()->findOrFail($customerId);

            if (! $customer->is_active) {
                return $customer;
            }

            $customer->is_active = false;
            $this->save($customer);

            $this->auditLogWriter->record(
                $lockedActor,
                $customer,
                'customer',
                'customer.deactivated',
                ['is_active' => true],
                ['is_active' => false],
            );

            return $customer;
        });
    }

    private function save(Customer $customer): void
    {
        if (! $customer->save()) {
            throw new RuntimeException('The customer could not be saved.');
        }
    }
}
