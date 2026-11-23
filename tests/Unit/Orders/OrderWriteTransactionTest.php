<?php

declare(strict_types=1);

namespace Tests\Unit\Orders;

use App\Enums\OrderWriteOperation;
use App\Exceptions\Orders\OrderActivityContentionException;
use App\Exceptions\Orders\OrderLifecycleContentionException;
use App\Exceptions\Orders\OrderWriteContentionException;
use App\Services\Orders\OrderWriteTransaction;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OrderWriteTransactionTest extends TestCase
{
    /**
     * @param  class-string<OrderWriteContentionException|OrderLifecycleContentionException|OrderActivityContentionException>  $exceptionClass
     */
    #[DataProvider('writeOperations')]
    public function test_exhausted_writes_keep_their_contention_response(
        OrderWriteOperation $operation,
        string $exceptionClass,
        string $warning,
        bool $unwrap,
    ): void {
        Config::set('orders.write_attempts', 2);
        $log = Log::spy();

        $cause = new PDOException('database is locked');
        $failure = new QueryException('sqlite', 'update orders set status = ?', ['ready'], $cause);
        $detector = $this->createMock(ConcurrencyErrorDetector::class);
        $detector->expects(self::once())
            ->method('causedByConcurrencyError')
            ->with(self::identicalTo($unwrap ? $cause : $failure))
            ->willReturn(true);

        $attempts = 0;
        $this->expectException($exceptionClass);

        try {
            (new OrderWriteTransaction($detector))->run(static function () use ($failure, &$attempts): void {
                $attempts++;
                throw $failure;
            }, $operation);

        } catch (OrderWriteContentionException|OrderLifecycleContentionException|OrderActivityContentionException $exception) {
            self::assertSame(2, $attempts);
            self::assertSame(0, DB::transactionLevel());
            self::assertTrue(Str::isUuid($exception->requestId));
            $log->shouldHaveReceived('warning')->once()->with($warning, ['request_id' => $exception->requestId]);

            throw $exception;
        }
    }

    /**
     * @return array<string, array{OrderWriteOperation, class-string<OrderWriteContentionException|OrderLifecycleContentionException|OrderActivityContentionException>, string, bool}>
     */
    public static function writeOperations(): array
    {
        return [
            'CRUD' => [OrderWriteOperation::Crud, OrderWriteContentionException::class, 'Order write could not acquire its database locks.', true],
            'Lifecycle' => [OrderWriteOperation::Lifecycle, OrderLifecycleContentionException::class, 'Order status change could not acquire its database locks.', false],
            'Activity' => [OrderWriteOperation::Activity, OrderActivityContentionException::class, 'Order activity could not acquire its database locks.', false],
        ];
    }
}
