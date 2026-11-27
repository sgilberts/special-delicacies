<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Define permissions
        $permissions = [
            // Menu permissions (Foundation & Phase 4 Granular)
            [
                'name' => 'View Menu',
                'slug' => 'menu.view',
                'description' => 'View menu categories and items',
            ],
            [
                'name' => 'Manage Menu',
                'slug' => 'menu.manage',
                'description' => 'Create, edit, and deactivate menu categories and items',
            ],
            [
                'name' => 'Edit Menu Prices',
                'slug' => 'menu.price.edit',
                'description' => 'Modify prices for menu items (Restricted from Waiter/Waitress)',
            ],
            [
                'name' => 'View Menu Categories',
                'slug' => 'menu.categories.view',
                'description' => 'View administrative menu categories listing and details',
            ],
            [
                'name' => 'Create Menu Categories',
                'slug' => 'menu.categories.create',
                'description' => 'Create new menu categories',
            ],
            [
                'name' => 'Update Menu Categories',
                'slug' => 'menu.categories.update',
                'description' => 'Edit menu categories and toggle active status',
            ],
            [
                'name' => 'Delete Menu Categories',
                'slug' => 'menu.categories.delete',
                'description' => 'Safely delete empty menu categories',
            ],
            [
                'name' => 'View Menu Items',
                'slug' => 'menu.items.view',
                'description' => 'View administrative menu items listing and details',
            ],
            [
                'name' => 'Create Menu Items',
                'slug' => 'menu.items.create',
                'description' => 'Create new restaurant menu items',
            ],
            [
                'name' => 'Update Menu Items',
                'slug' => 'menu.items.update',
                'description' => 'Edit menu item descriptions, categories, and details',
            ],
            [
                'name' => 'Delete Menu Items',
                'slug' => 'menu.items.delete',
                'description' => 'Safely delete menu items',
            ],
            [
                'name' => 'Update Menu Item Prices',
                'slug' => 'menu.items.update_price',
                'description' => 'Modify prices for menu items in Ghana Cedis',
            ],
            [
                'name' => 'Update Menu Item Availability',
                'slug' => 'menu.items.update_availability',
                'description' => 'Mark menu items as available or unavailable',
            ],
            [
                'name' => 'Upload Menu Item Images',
                'slug' => 'menu.items.upload_image',
                'description' => 'Upload, replace, and remove menu item photographs',
            ],

            // Order permissions
            [
                'name' => 'View Orders',
                'slug' => 'orders.view',
                'description' => 'View order details and status histories',
            ],
            [
                'name' => 'Create Orders',
                'slug' => 'orders.create',
                'description' => 'Create new dine-in or delivery orders',
            ],
            [
                'name' => 'Update Order Status',
                'slug' => 'orders.status.update',
                'description' => 'Update operational status of orders (preparing, ready, delivered, etc.)',
            ],
            [
                'name' => 'Confirm Orders',
                'slug' => 'orders.confirm',
                'description' => 'Confirm pending dine-in or delivery orders (Waiter/Super Admin)',
            ],
            [
                'name' => 'Prepare Orders',
                'slug' => 'orders.prepare',
                'description' => 'Start food preparation of confirmed orders (Kitchen Staff/Super Admin)',
            ],
            [
                'name' => 'Mark Orders Ready',
                'slug' => 'orders.ready',
                'description' => 'Mark prepared orders as ready for waiter pickup (Kitchen Staff/Super Admin)',
            ],
            [
                'name' => 'Deliver Orders',
                'slug' => 'orders.deliver',
                'description' => 'Deliver ready food orders to restaurant tables (Waiter/Super Admin)',
            ],
            [
                'name' => 'Complete Orders',
                'slug' => 'orders.complete',
                'description' => 'Complete delivered orders and close tickets (Waiter/Super Admin)',
            ],
            [
                'name' => 'Cancel Orders',
                'slug' => 'orders.cancel',
                'description' => 'Cancel orders with authorized status checks',
            ],

            // Restaurant Tables & QR permissions
            [
                'name' => 'View Tables',
                'slug' => 'tables.view',
                'description' => 'View restaurant tables and active QR code status',
            ],
            [
                'name' => 'Manage Tables',
                'slug' => 'tables.manage',
                'description' => 'Create, edit tables and generate or revoke QR tokens',
            ],

            // Delivery zones permissions
            [
                'name' => 'View Delivery Zones',
                'slug' => 'delivery_zones.view',
                'description' => 'View delivery zones and configured delivery fees',
            ],
            [
                'name' => 'Manage Delivery Zones',
                'slug' => 'delivery_zones.manage',
                'description' => 'Create, edit, and configure delivery zones and fees',
            ],

            // Phase 5 granular table permissions
            ['name' => 'Create Tables', 'slug' => 'tables.create', 'description' => 'Create new restaurant tables'],
            ['name' => 'Update Tables', 'slug' => 'tables.update', 'description' => 'Edit table details and toggle active status'],
            ['name' => 'Delete Tables', 'slug' => 'tables.delete', 'description' => 'Safely delete restaurant tables when no orders exist'],
            ['name' => 'View Table QR Codes', 'slug' => 'tables.qr.view', 'description' => 'View QR code status for restaurant tables'],
            ['name' => 'Generate Table QR Codes', 'slug' => 'tables.qr.generate', 'description' => 'Generate and regenerate QR codes for tables'],
            ['name' => 'Revoke Table QR Codes', 'slug' => 'tables.qr.revoke', 'description' => 'Revoke active QR codes for restaurant tables'],

            // Delivery permissions (Phase 8)
            [
                'name'        => 'View Delivery Orders',
                'slug'        => 'delivery.orders.view',
                'description' => 'View delivery orders assigned to or available for the delivery staff',
            ],
            [
                'name'        => 'Assign Delivery Orders',
                'slug'        => 'delivery.orders.assign',
                'description' => 'Self-assign ready delivery orders for dispatch',
            ],
            [
                'name'        => 'Dispatch Delivery Orders',
                'slug'        => 'delivery.orders.dispatch',
                'description' => 'Mark delivery orders as out-for-delivery',
            ],
            [
                'name'        => 'Confirm Delivery Received',
                'slug'        => 'delivery.orders.deliver',
                'description' => 'Mark delivery orders as delivered to the customer',
            ],

            // Settings permissions
            [
                'name' => 'View Settings',
                'slug' => 'settings.view',
                'description' => 'View restaurant operational settings',
            ],
            [
                'name' => 'Manage Settings',
                'slug' => 'settings.manage',
                'description' => 'Modify restaurant configuration (Restricted from Kitchen Staff)',
            ],

            // Staff permissions
            [
                'name' => 'View Staff',
                'slug' => 'staff.view',
                'description' => 'View staff directory and details',
            ],
            [
                'name' => 'Manage Staff',
                'slug' => 'staff.manage',
                'description' => 'Manage staff accounts and assign roles',
            ],

            // Role and permission management
            [
                'name' => 'Manage Roles and Permissions',
                'slug' => 'roles.manage',
                'description' => 'View and manage roles and assignable permissions',
            ],

            // Website content permissions
            [
                'name' => 'Manage Website Content',
                'slug' => 'content.manage',
                'description' => 'Manage restaurant public website copy, banners, and gallery',
            ],

            // Audit trail permissions
            [
                'name' => 'View Audit Trail',
                'slug' => 'audit.view',
                'description' => 'View administrative audit trail and historical activity',
            ],
            ['name' => 'View Financial Dashboard', 'slug' => 'financials.view', 'description' => 'View financial dashboard and aggregates'],
            ['name' => 'View Reverb Monitor', 'slug' => 'reverb.monitor', 'description' => 'View live broadcast diagnostics and send test pings'],
            ['name' => 'View Financial Transactions', 'slug' => 'financials.transactions.view', 'description' => 'View transaction register and payment details'],
            ['name' => 'View Financial Reports', 'slug' => 'financials.reports.view', 'description' => 'View financial reports'],
            ['name' => 'Export Financial Reports', 'slug' => 'financials.reports.export', 'description' => 'Export validated financial reports'],
            ['name' => 'View Payment Reconciliation', 'slug' => 'financials.reconciliation.view', 'description' => 'View payment reconciliation queue'],
            ['name' => 'View Payment Allocations', 'slug' => 'financials.allocations.view', 'description' => 'View eligible invoice receipts and payment allocation history'],
            ['name' => 'Manage Payment Allocations', 'slug' => 'financials.allocations.manage', 'description' => 'Allocate eligible receipts to issued invoices'],
            ['name' => 'Reverse Payment Allocations', 'slug' => 'financials.allocations.reverse', 'description' => 'Reverse an allocation with an audited reason'],
            ['name' => 'View Deposit and Advance Receipts', 'slug' => 'financials.deposits.view', 'description' => 'View eligible deposit and advance-payment receipts'],
            ['name' => 'Manage Deposit and Advance Receipts', 'slug' => 'financials.deposits.manage', 'description' => 'Classify verified receipts as deposits or advances'],
            ['name' => 'View Customer Credit', 'slug' => 'financials.customer_credit.view', 'description' => 'View customer and organisation credit balances and ledger history'],
            ['name' => 'Manage Customer Credit', 'slug' => 'financials.customer_credit.manage', 'description' => 'Designate eligible receipts as credit and apply credit to invoices'],
            ['name' => 'View Invoice Payment Balances', 'slug' => 'financials.invoice_balances.view', 'description' => 'View derived invoice payment balances'],
            ['name' => 'Confirm Cash Payments', 'slug' => 'financials.payments.confirm_cash', 'description' => 'Confirm cash collection for an order'],
            ['name' => 'View Refunds', 'slug' => 'financials.refunds.view', 'description' => 'View refund requests, provider status and refund history'],
            ['name' => 'Initiate Refunds', 'slug' => 'financials.refunds.initiate', 'description' => 'Request a refund against an eligible payment receipt'],
            ['name' => 'Approve Refunds', 'slug' => 'financials.refunds.approve', 'description' => 'Approve, submit and confirm or reject refund requests'],
            ['name' => 'View Credit Notes', 'slug' => 'financials.credit_notes.view', 'description' => 'View credit notes and their originating invoices'],
            ['name' => 'Create Credit Notes', 'slug' => 'financials.credit_notes.create', 'description' => 'Create draft credit notes against eligible invoices'],
            ['name' => 'Approve Credit Notes', 'slug' => 'financials.credit_notes.approve', 'description' => 'Issue or void a draft credit note'],
            ['name' => 'View Credit Reversals', 'slug' => 'financials.credit_reversals.view', 'description' => 'View customer credit reversal requests and history'],
            ['name' => 'Initiate Credit Reversals', 'slug' => 'financials.credit_reversals.initiate', 'description' => 'Request a reversal of unspent customer credit'],
            ['name' => 'Approve Credit Reversals', 'slug' => 'financials.credit_reversals.approve', 'description' => 'Approve or reject a customer credit reversal'],
            ['name' => 'View Commercial Documents', 'slug' => 'commercial_documents.view', 'description' => 'View quotations and invoices'],
            ['name' => 'Manage Commercial Documents', 'slug' => 'commercial_documents.manage', 'description' => 'Create and edit draft quotations and invoices'],
            ['name' => 'Issue Commercial Documents', 'slug' => 'commercial_documents.issue', 'description' => 'Issue quotations and invoices'],
            ['name' => 'Void Commercial Documents', 'slug' => 'commercial_documents.void', 'description' => 'Void issued invoices with an audited reason'],
            ['name' => 'Receive Chat Conversations', 'slug' => 'chat.receive', 'description' => 'Receive automatically assigned customer conversations'],
            ['name' => 'View Assigned Chats', 'slug' => 'chat.view_assigned', 'description' => 'View conversations assigned to the current staff member'],
            ['name' => 'View All Chats', 'slug' => 'chat.view_all', 'description' => 'View all customer conversations'],
            ['name' => 'Reply to Chats', 'slug' => 'chat.reply', 'description' => 'Send replies in authorized conversations'],
            ['name' => 'Assign Chats', 'slug' => 'chat.assign', 'description' => 'Assign conversations to eligible staff'],
            ['name' => 'Reassign Chats', 'slug' => 'chat.reassign', 'description' => 'Change the assigned staff member'],
            ['name' => 'Close Chats', 'slug' => 'chat.close', 'description' => 'Close or reopen conversations'],
            ['name' => 'Delete Chat Messages', 'slug' => 'chat.delete_message', 'description' => 'Tombstone messages while preserving audit evidence'],
            ['name' => 'Manage Chat Settings', 'slug' => 'chat.manage_settings', 'description' => 'Manage chat availability and assignment settings'],
            ['name' => 'View Chat Audit Logs', 'slug' => 'chat.view_audit_logs', 'description' => 'Review conversation audit history'],

            // Customer support / customer management. Granted explicitly per
            // role in Roles & Permissions; never handed to every administrator.
            ['name' => 'View Customers', 'slug' => 'customers.view', 'description' => 'View customer records and customer profiles'],
            ['name' => 'Create Customers', 'slug' => 'customers.create', 'description' => 'Create customer records from the admin portal'],
            ['name' => 'Update Customers', 'slug' => 'customers.update', 'description' => 'Edit customer profile and contact details'],
            ['name' => 'Archive Customers', 'slug' => 'customers.archive', 'description' => 'Archive a customer while preserving financial and audit history'],
            ['name' => 'Restore Customers', 'slug' => 'customers.restore', 'description' => 'Restore an archived customer to active status'],
            ['name' => 'View Customer Financials', 'slug' => 'customers.view_financials', 'description' => 'View a customer\'s payments, deposits, invoices, refunds and credit balance'],
        ];

        $permissionModels = [];
        foreach ($permissions as $perm) {
            $permissionModels[$perm['slug']] = Permission::updateOrCreate(
                ['slug' => $perm['slug']],
                ['name' => $perm['name'], 'description' => $perm['description']]
            );
        }

        // 2. Define the four approved roles
        $roles = [
            'super-admin' => [
                'name' => 'Super Admin',
                'slug' => 'super-admin',
                'description' => 'Full administrative access to restaurant operations, settings, menu, and staff.',
                'permissions' => array_keys($permissionModels), // Super Admin has all permissions
            ],
            'admin' => [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Restaurant administrator; access is controlled by assigned permissions.',
                // Customer management is granted explicitly per role in Roles &
                // Permissions, never to every administrator by default. The
                // migration that creates these permissions grants them to
                // super-admin only, so a re-seed never silently removes a grant.
                'permissions' => array_values(array_filter(
                    array_keys($permissionModels),
                    fn ($slug) => ! str_starts_with($slug, 'financials.')
                        && ! str_starts_with($slug, 'chat.')
                        && ! str_starts_with($slug, 'customers.')
                        && $slug !== 'reverb.monitor'
                )),
            ],
            'waiter-waitress' => [
                'name' => 'Waiter/Waitress',
                'slug' => 'waiter-waitress',
                'description' => 'Service staff handling table orders, status updates, and viewing menu. Cannot edit prices.',
                'permissions' => [
                    'menu.view',
                    'orders.view',
                    'orders.create',
                    'orders.status.update',
                    'orders.confirm',
                    'orders.deliver',
                    'orders.complete',
                ],
            ],
            'kitchen-staff' => [
                'name' => 'Kitchen Staff',
                'slug' => 'kitchen-staff',
                'description' => 'Kitchen operations staff managing food preparation and order readiness. Cannot access settings.',
                'permissions' => [
                    'menu.view',
                    'orders.view',
                    'orders.prepare',
                    'orders.ready',
                ],
            ],
            'delivery-staff' => [
                'name'        => 'Delivery Staff',
                'slug'        => 'delivery-staff',
                'description' => 'Delivery personnel managing out-for-delivery orders and completion status.',
                'permissions' => [
                    'menu.view',
                    'orders.view',
                    'delivery_zones.view',
                    'delivery.orders.view',
                    'delivery.orders.assign',
                    'delivery.orders.dispatch',
                    'delivery.orders.deliver',
                ],
            ],
        ];

        foreach ($roles as $roleKey => $roleData) {
            $role = Role::updateOrCreate(
                ['slug' => $roleData['slug']],
                ['name' => $roleData['name'], 'description' => $roleData['description']]
            );

            // Super Admin is defined as having every staff permission. Include
            // permissions registered by approved migrations as well as the
            // catalogue above, without crossing into organisation permissions.
            if ($roleKey === 'super-admin') {
                $permIds = Permission::query()->pluck('id')->all();
            } else {
                $permIds = [];
                foreach ($roleData['permissions'] as $slug) {
                    if (isset($permissionModels[$slug])) {
                        $permIds[] = $permissionModels[$slug]->id;
                    }
                }
            }
            $role->permissions()->sync($permIds);
        }
    }
}
