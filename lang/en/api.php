<?php

declare(strict_types=1);

return [
    'auth' => [
        'logged_in' => 'Logged in successfully.',
        'logged_out' => 'Logged out successfully.',
        'password_changed' => 'Password changed successfully.',
        'password_reset_link_requested' => 'If an account can use that email, a password reset link will be sent.',
        'password_reset' => 'Password reset successfully.',
        'password_reset_link_invalid' => 'This password reset link is invalid or expired.',
        'password_unchanged' => 'The replacement password must be different from your current password.',
        'current_user_retrieved' => 'Authenticated user retrieved.',
        'invalid_credentials' => 'The email or password is incorrect.',
        'unauthenticated' => 'Authentication is required.',
    ],

    'users' => [
        'list_retrieved' => 'Users retrieved successfully.',
        'retrieved' => 'User retrieved successfully.',
        'created' => 'User created successfully.',
        'updated' => 'User updated successfully.',
        'enabled' => 'User enabled successfully.',
        'last_active_admin' => 'At least one active Admin must remain.',
        'permissions_retrieved' => 'User permissions retrieved successfully.',
        'permissions_updated' => 'Direct user permissions updated successfully.',
        'admin_only_permissions' => 'These permissions can only be added to users with the built-in Admin role: :permissions.',
    ],

    'access_audit' => [
        'list_retrieved' => 'Access audit records retrieved successfully.',
        'retrieved' => 'Access audit record retrieved successfully.',
    ],

    'dashboard' => [
        'overview_retrieved' => 'Dashboard overview retrieved.',
        'revenue_trend_retrieved' => 'Revenue trend retrieved.',
        'revenue_range_exceeded' => 'The revenue date range may span at most :days days.',
    ],

    'reports' => [
        'order_fulfillment_retrieved' => 'Order fulfillment report retrieved.',
        'sales_tax_retrieved' => 'Sales and tax report retrieved.',
        'date_range_exceeded' => 'The report date range may span at most :days days.',
    ],

    'operational_audit' => [
        'list_retrieved' => 'Operational audit records retrieved successfully.',
        'retrieved' => 'Operational audit record retrieved successfully.',
    ],

    'orders' => [
        'export_limit' => 'More than :limit orders match. Narrow the filters and try again.',
        'assigned' => 'Order assignment updated successfully.',
        'assignees_retrieved' => 'Assignable employees retrieved successfully.',
        'note_added' => 'Order note added successfully.',
        'assignment_not_allowed' => 'This order can no longer be assigned.',
        'activity_write_contention' => 'The order activity could not be saved because a record is busy. Try again after the retry interval.',
        'crud_write_contention' => 'The order could not be saved because a record is busy. Try again after the retry interval.',
        'status_updated' => 'Order status updated successfully.',
        'invalid_transition' => 'The order cannot change from :from to :to.',
        'confirmation_product_unavailable' => 'Product :sku is no longer active and cannot be confirmed.',
        'insufficient_stock' => 'Not enough stock is available for product :sku.',
        'restoration_stock_limit' => 'Cancelling this order would exceed the stock limit for product :sku.',
        'write_contention' => 'The order status could not be saved because a record is busy. Try again after the retry interval.',
        'list_retrieved' => 'Orders retrieved successfully.',
        'retrieved' => 'Order retrieved successfully.',
        'created' => 'Order created successfully.',
        'idempotency_key_invalid' => 'Provide one Idempotency-Key header of 1 to 128 characters using letters, numbers, dots, underscores, tildes, or hyphens.',
        'idempotency_conflict' => 'This idempotency key has already been used with a different order request.',
        'updated' => 'Order updated successfully.',
        'deleted' => 'Order deleted successfully.',
        'pricing_previewed' => 'Order pricing calculated successfully.',
        'fields_required' => 'Provide at least one order field to update.',
        'unexpected_fields' => 'The order contains fields that cannot be submitted.',
        'items_required' => 'An order must contain at least one item.',
        'product_repeated' => 'A product may appear only once in an order.',
        'invalid_pricing_input' => 'The quantity or catalog pricing is invalid for this item.',
        'order_tax_rate_out_of_range' => 'The order tax rate must be between 0 and 10000 basis points.',
        'order_tax_conflicts_with_item_rates' => 'Order-level tax settings cannot be combined with item tax overrides.',
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

    'notifications' => [
        'list_retrieved' => 'Notifications retrieved successfully.',
        'unread_count_retrieved' => 'Unread notification count retrieved successfully.',
        'marked_read' => 'Notification marked as read.',
        'marked_all_read' => 'Visible notifications marked as read.',
    ],

    'customers' => [
        'list_retrieved' => 'Customers retrieved successfully.',
        'retrieved' => 'Customer retrieved successfully.',
        'order_summary_retrieved' => 'Customer order summary retrieved successfully.',
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
