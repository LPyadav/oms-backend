<?php

declare(strict_types=1);

return [
    'users' => env('OMS_DEMO_USERS', 500),
    'customers' => env('OMS_DEMO_CUSTOMERS', 100),
    'products' => env('OMS_DEMO_PRODUCTS', 50),
    'orders' => env('OMS_DEMO_ORDERS', 504),
    'order_batch_size' => env('OMS_DEMO_ORDER_BATCH_SIZE', 100),
    'password' => env('OMS_DEMO_PASSWORD', 'OmsDemo!500'),
];
