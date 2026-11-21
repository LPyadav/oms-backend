<?php

namespace Tests\Unit;

use App\Http\Responses\ApiResponse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ExampleTest extends TestCase
{
    public function test_json_success_rejects_a_status_without_a_body(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ApiResponse)->success(
            message: 'Operation completed.',
            status: Response::HTTP_NO_CONTENT,
        );
    }
}
