<?php

declare(strict_types=1);

return [
    'auth' => [
        'logged_in' => 'Logged in successfully.',
        'logged_out' => 'Logged out successfully.',
        'current_user_retrieved' => 'Authenticated user retrieved.',
        'invalid_credentials' => 'The email or password is incorrect.',
        'unauthenticated' => 'Authentication is required.',
    ],

    'users' => [
        'list_retrieved' => 'Users retrieved successfully.',
        'created' => 'User created successfully.',
        'updated' => 'User updated successfully.',
        'enabled' => 'User enabled successfully.',
        'last_active_admin' => 'At least one active Admin must remain.',
        'permissions_retrieved' => 'User permissions retrieved successfully.',
        'permissions_updated' => 'Direct user permissions updated successfully.',
    ],

    'orders' => [
        'list_retrieved' => 'Orders retrieved successfully.',
        'retrieved' => 'Order retrieved successfully.',
        'created' => 'Order created successfully.',
        'updated' => 'Order updated successfully.',
        'deleted' => 'Order deleted successfully.',
        'pricing_previewed' => 'Order pricing calculated successfully.',
        'fields_required' => 'Provide at least one order field to update.',
        'unexpected_fields' => 'The order contains fields that cannot be submitted.',
        'items_required' => 'An order must contain at least one item.',
        'product_repeated' => 'A product may appear only once in an order.',
        'invalid_pricing_input' => 'The quantity or catalog pricing is invalid for this item.',
        'item_discount_exceeds_subtotal' => 'The item discount must be between zero and the item subtotal.',
        'order_discount_exceeds_eligible_amount' => 'The order discount must be between zero and the total remaining after item discounts.',
        'amount_out_of_range' => 'The calculated order amounts exceed the supported range.',
        'customer_unavailable' => 'Select an active customer.',
        'product_unavailable' => 'Select an active product.',
        'assignee_unavailable' => 'Select an active employee.',
        'not_editable' => 'Only draft and pending orders can be edited.',
        'not_deletable' => 'Only draft, pending, and cancelled orders can be deleted.',
        'version_limit' => 'This order cannot accept another version.',
        'version_conflict' => 'The order changed since it was last read. Fetch the order and try again.',
        'note_invalid' => 'Provide a non-empty note within the permitted length.',
        'catalog_currency_changed' => 'New products cannot be added because this order uses a different currency from the current catalog.',
    ],

    'customers' => [
        'list_retrieved' => 'Customers retrieved successfully.',
        'retrieved' => 'Customer retrieved successfully.',
        'created' => 'Customer created successfully.',
        'updated' => 'Customer updated successfully.',
        'deactivated' => 'Customer deactivated successfully.',
        'fields_required' => 'Provide at least one customer field to update.',
    ],

    'products' => [
        'list_retrieved' => 'Products retrieved successfully.',
        'retrieved' => 'Product retrieved successfully.',
        'created' => 'Product created successfully.',
        'updated' => 'Product updated successfully.',
        'deactivated' => 'Product deactivated successfully.',
        'stock_adjusted' => 'Product stock adjusted successfully.',
        'fields_required' => 'Provide at least one product field to update.',
        'stock_version_conflict' => 'The stock changed since it was last read. Fetch the product and try again.',
    ],

    'roles' => [
        'list_retrieved' => 'Roles retrieved successfully.',
        'created' => 'Role created successfully.',
        'updated' => 'Role updated successfully.',
        'permissions_updated' => 'Role permissions updated successfully.',
        'admin_only_permissions' => 'These permissions are reserved for the built-in Admin role: :permissions.',
    ],

    'permissions' => [
        'list_retrieved' => 'Permissions retrieved successfully.',
    ],

    'health' => [
        'ok' => 'The API is healthy.',
    ],

    'errors' => [
        'validation_failed' => 'The submitted data is invalid.',
        'access_forbidden' => 'You do not have permission to perform this action.',
        'resource_not_found' => 'The requested resource was not found.',
        'method_not_allowed' => 'The request method is not supported for this endpoint.',
        'rate_limit_exceeded' => 'Too many requests. Please try again later.',
        'request_failed' => 'The request could not be processed.',
        'write_contention' => 'The requested change could not be saved because the catalog is busy. Try again after the retry interval.',
        'internal_server_error' => 'An unexpected error occurred.',
    ],
];
